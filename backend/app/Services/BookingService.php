<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\OperatorStatus;
use App\Enums\PaymentMethod;
use App\Enums\TourStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Tour;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;

/*
| Everything that happens when a traveler books or cancels a tour.
|
| Why a service class and not code in the controller?
|   - The controller's job is HTTP: read the request, return a response.
|   - Booking rules (spots, prices, discounts, payment) are business logic.
|     Keeping them here makes them reusable (an admin "book on behalf of"
|     feature, a CLI import, ...) and easy to test without HTTP.
|
| Two guarantees this class gives:
|   1. All-or-nothing: spots, discount usage, booking row and wallet debit
|      are written in one database transaction. If any step fails, none of
|      them happened.
|   2. No races: the tour row is locked (SELECT ... FOR UPDATE) while spots
|      are counted, so two travelers can never both get "the last spot".
*/
final class BookingService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly WalletService $wallets,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @throws BusinessRuleException
     */
    public function book(User $user, Tour $tour, int $spots, PaymentMethod $method, ?string $discountCode): Booking
    {
        return match ($method) {
            PaymentMethod::Wallet => $this->bookWithWallet($user, $tour, $spots, $discountCode),
            PaymentMethod::Card => $this->bookWithCard($user, $tour, $spots, $discountCode),
        };
    }

    /**
     * Wallet payments live in our own database, so the whole booking can be a
     * single transaction: either everything is saved, or nothing is.
     */
    private function bookWithWallet(User $user, Tour $tour, int $spots, ?string $discountCode): Booking
    {
        return DB::transaction(function () use ($user, $tour, $spots, $discountCode) {
            [$lockedTour, $quote] = $this->reserveSpots($user, $tour, $spots, $discountCode);

            $booking = $this->createBooking($user, $lockedTour, $quote, PaymentMethod::Wallet, BookingStatus::Confirmed);

            // A 100% discount leaves nothing to pay.
            if ($quote->totalCents > 0) {
                // Throws if the balance is too low, which rolls back the spots too.
                $this->wallets->debit($user->wallet, $quote->totalCents, "Booking {$booking->reference}", $booking);
            }

            return $booking;
        });
    }

    /**
     * Card payments involve an external service (the payment provider).
     *
     * Rule of thumb: never call another server while holding database locks.
     * The call may take seconds or time out, and every other booking for this
     * tour would wait behind it. So the work is split in three steps:
     *   1. short transaction: hold the spots with a `pending` booking
     *   2. no transaction:    charge the card
     *   3. short transaction: confirm the booking, or release the spots
     */
    private function bookWithCard(User $user, Tour $tour, int $spots, ?string $discountCode): Booking
    {
        $booking = DB::transaction(function () use ($user, $tour, $spots, $discountCode) {
            [$lockedTour, $quote] = $this->reserveSpots($user, $tour, $spots, $discountCode);

            return $this->createBooking($user, $lockedTour, $quote, PaymentMethod::Card, BookingStatus::Pending);
        });

        if ($booking->total_cents === 0) {
            return $this->markPaid($booking, null);
        }

        $result = $this->gateway->charge(
            $booking->total_cents,
            config('payments.currency'),
            "Booking {$booking->reference}",
        );

        if (! $result->successful) {
            $this->releaseSpots($booking, BookingStatus::Failed);

            throw BusinessRuleException::paymentDeclined($result->failureMessage);
        }

        return $this->markPaid($booking, $result->reference);
    }

    /**
     * Cancelling refunds the full amount to the traveler's wallet as travel
     * credit (a product decision, see docs/05-bookings-and-payments.md).
     *
     * @throws BusinessRuleException
     */
    public function cancel(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('tour', 'user.wallet');

            if (! $locked->isCancellable()) {
                throw BusinessRuleException::bookingNotCancellable();
            }

            $this->releaseSpots($locked, BookingStatus::Cancelled);

            if ($locked->total_cents > 0) {
                $this->wallets->credit(
                    $locked->user->wallet,
                    $locked->total_cents,
                    "Refund for cancelled booking {$locked->reference}",
                    $locked,
                );
            }

            return $locked;
        });
    }

    /**
     * Locks the tour, checks it can take `$spots` more travelers, prices the
     * booking and claims the spots (and the discount use).
     *
     * Must be called inside a transaction, otherwise the lock is useless.
     *
     * @return array{0: Tour, 1: Quote}
     */
    private function reserveSpots(User $user, Tour $tour, int $spots, ?string $discountCode): array
    {
        /** @var Tour $locked */
        $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
        $locked->load('operator.operatorProfile');

        $bookable = $locked->status === TourStatus::Published
            && $locked->operator->operatorProfile?->status === OperatorStatus::Approved
            && ! $locked->hasStarted();

        if (! $bookable) {
            throw BusinessRuleException::tourNotBookable();
        }

        if ($locked->available_spots < $spots) {
            throw BusinessRuleException::notEnoughSpots($locked->available_spots);
        }

        $quote = $this->pricing->quote($locked, $spots, $discountCode, $user, lockDiscount: true);

        // increment() issues `UPDATE tours SET booked_spots = booked_spots + ?`.
        // The CHECK constraint (booked_spots <= capacity) guards it as well.
        $locked->increment('booked_spots', $spots);
        $quote->discount?->increment('redemptions_count');

        return [$locked, $quote];
    }

    private function createBooking(User $user, Tour $tour, Quote $quote, PaymentMethod $method, BookingStatus $status): Booking
    {
        $booking = new Booking;
        $booking->forceFill([
            'reference' => $this->generateReference(),
            'spots' => $quote->spots,
            'unit_price_cents' => $quote->unitPriceCents,
            'subtotal_cents' => $quote->subtotalCents,
            'discount_cents' => $quote->discountCents,
            'total_cents' => $quote->totalCents,
            'payment_method' => $method,
            'status' => $status,
            'paid_at' => $status === BookingStatus::Confirmed ? now() : null,
        ]);
        $booking->user()->associate($user);
        $booking->tour()->associate($tour);
        $booking->discount()->associate($quote->discount);
        $booking->save();

        return $booking;
    }

    private function markPaid(Booking $booking, ?string $paymentReference): Booking
    {
        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'payment_reference' => $paymentReference,
            'paid_at' => now(),
        ])->save();

        return $booking;
    }

    /** Gives the spots (and the discount use) back and records the new status. */
    private function releaseSpots(Booking $booking, BookingStatus $newStatus): void
    {
        DB::transaction(function () use ($booking, $newStatus) {
            Tour::query()->whereKey($booking->tour_id)->lockForUpdate()->firstOrFail()
                ->decrement('booked_spots', $booking->spots);

            if ($booking->discount_id !== null) {
                Discount::query()->whereKey($booking->discount_id)->decrement('redemptions_count');
            }

            $booking->forceFill([
                'status' => $newStatus,
                'cancelled_at' => $newStatus === BookingStatus::Cancelled ? now() : null,
            ])->save();
        });
    }

    /**
     * A reference like "LT-7KQ2M9XA". The alphabet skips characters that are
     * easy to confuse on the phone or on paper (0/O, 1/I/L).
     * random_int() is cryptographically secure, unlike rand().
     */
    private function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return "LT-{$code}";
    }
}
