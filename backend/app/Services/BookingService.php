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
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /*
    | Expires card bookings that have been `pending` for longer than
    | config('payments.pending_timeout_minutes'). Run every minute by the
    | bookings:expire-pending command.
    |
    | Why this exists: a card booking holds its spots as `pending` while the
    | card is charged (see bookWithCard). If the server crashes between the
    | charge and the confirmation, nothing ever confirms or releases it, and
    | its spots and discount use would be held forever.
    |
    | Why a new public method and not just making releaseSpots() public:
    | releaseSpots() does not check the booking. Called on a booking that is
    | already cancelled or failed, it would give the same spots back a second
    | time. This method locks each booking and re-checks it is still pending
    | and still too old, like cancel() does, so running it twice, or twice at
    | the same time, is harmless.
    |
    | Each booking gets its own short transaction: locks are held briefly and
    | one bad row cannot roll back the others. chunkById() pages by id, so it
    | stays correct while the rows it already handled change status.
    |
    | No refund here: we cannot ask the (fake) gateway whether a stuck
    | booking was charged, and crediting a card that was never charged would
    | hand out free money. Each expiry is logged for manual reconciliation.
    | See D18 in docs/decisions.md.
    |
    | Returns how many bookings were expired.
    */
    public function expireStalePending(): int
    {
        $cutoff = now()->subMinutes((int) config('payments.pending_timeout_minutes'));
        $expired = 0;

        Booking::query()
            ->where('status', BookingStatus::Pending)
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->chunkById(100, function ($bookings) use ($cutoff, &$expired) {
                foreach ($bookings as $candidate) {
                    $booking = $this->expireIfStale($candidate->id, $cutoff);

                    if ($booking !== null) {
                        $expired++;
                        Log::warning('Expired a stale pending card booking; check with the payment provider whether it was charged.', [
                            'booking' => $booking->reference,
                            'total_cents' => $booking->total_cents,
                        ]);
                    }
                }
            });

        return $expired;
    }

    /** Returns the expired booking, or null if it was no longer stale (confirmed, failed, ...). */
    private function expireIfStale(int $bookingId, CarbonInterface $cutoff): ?Booking
    {
        return DB::transaction(function () use ($bookingId, $cutoff) {
            /** @var Booking|null $locked */
            $locked = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();

            // Re-check under the lock: the payment may have just been confirmed,
            // or another run of the command may have expired it already.
            if ($locked === null
                || $locked->status !== BookingStatus::Pending
                || $locked->created_at->greaterThanOrEqualTo($cutoff)) {
                return null;
            }

            $this->releaseSpots($locked, BookingStatus::Failed);

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

    /**
     * Confirms a pending card booking after the charge succeeded.
     *
     * The booking is locked and re-checked first. If the charge took longer
     * than the pending timeout, bookings:expire-pending may already have
     * marked it failed and given its spots to someone else. Confirming it
     * then would overbook the tour. Instead the booking stays failed and,
     * because we know the card was charged, the amount is credited to the
     * traveler's wallet (the same travel-credit policy as cancel()).
     *
     * @throws BusinessRuleException when the booking expired during the charge
     */
    private function markPaid(Booking $booking, ?string $paymentReference): Booking
    {
        $confirmed = DB::transaction(function () use ($booking, $paymentReference) {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending) {
                $locked->load('user.wallet');

                if ($locked->total_cents > 0) {
                    $this->wallets->credit(
                        $locked->user->wallet,
                        $locked->total_cents,
                        "Refund for expired booking {$locked->reference}",
                        $locked,
                    );
                }

                Log::warning('A card charge succeeded after its booking expired; refunded to the wallet.', [
                    'booking' => $locked->reference,
                    'payment_reference' => $paymentReference,
                ]);

                return null;
            }

            $locked->forceFill([
                'status' => BookingStatus::Confirmed,
                'payment_reference' => $paymentReference,
                'paid_at' => now(),
            ])->save();

            return $locked;
        });

        // Thrown after the transaction, so the wallet credit is committed.
        if ($confirmed === null) {
            throw BusinessRuleException::bookingExpired();
        }

        return $confirmed;
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
