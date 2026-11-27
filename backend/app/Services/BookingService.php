<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\OperatorStatus;
use App\Enums\PaymentMethod;
use App\Enums\TourStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Discount;
use App\Models\Tour;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentOutcome;
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
|   2. No races: the departure row is locked (SELECT ... FOR UPDATE) while
|      its spots are counted, so two travelers can never both get "the last
|      spot" of a date.
|
| Since Exercise 10 a booking is for a departure (one date of a tour), and
| capacity and the booked_spots counter live on the departure (D23). The
| price still comes from the tour.
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
    public function book(User $user, Departure $departure, int $spots, PaymentMethod $method, ?string $discountCode): Booking
    {
        return match ($method) {
            PaymentMethod::Wallet => $this->bookWithWallet($user, $departure, $spots, $discountCode),
            PaymentMethod::Card => $this->bookWithCard($user, $departure, $spots, $discountCode),
        };
    }

    /**
     * Wallet payments live in our own database, so the whole booking can be a
     * single transaction: either everything is saved, or nothing is.
     */
    private function bookWithWallet(User $user, Departure $departure, int $spots, ?string $discountCode): Booking
    {
        return DB::transaction(function () use ($user, $departure, $spots, $discountCode) {
            [$lockedDeparture, $quote] = $this->reserveSpots($user, $departure, $spots, $discountCode);

            $booking = $this->createBooking($user, $lockedDeparture, $quote, PaymentMethod::Wallet, BookingStatus::Confirmed);

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
     * departure would wait behind it. So the work is split in three steps:
     *   1. short transaction: hold the spots with a `pending` booking
     *   2. no transaction:    start the payment with the gateway
     *   3. short transaction: depending on the gateway's answer,
     *        paid        → confirm the booking
     *        redirect    → store the Checkout Session; the booking stays
     *                      pending until Stripe's webhook settles it
     *                      (confirmCheckout / expireCheckout)
     *        declined,
     *        unavailable → release the spots
     */
    private function bookWithCard(User $user, Departure $departure, int $spots, ?string $discountCode): Booking
    {
        $booking = DB::transaction(function () use ($user, $departure, $spots, $discountCode) {
            [$lockedDeparture, $quote] = $this->reserveSpots($user, $departure, $spots, $discountCode);

            return $this->createBooking($user, $lockedDeparture, $quote, PaymentMethod::Card, BookingStatus::Pending);
        });

        if ($booking->total_cents === 0) {
            return $this->markPaid($booking, null);
        }

        $start = $this->gateway->startPayment($booking);

        return match ($start->outcome) {
            PaymentOutcome::Paid => $this->markPaid($booking, $start->reference),
            PaymentOutcome::Redirect => $this->awaitCheckout($booking, $start->sessionId, $start->checkoutUrl),
            PaymentOutcome::Declined => $this->failPayment($booking, BusinessRuleException::paymentDeclined($start->failureMessage)),
            PaymentOutcome::Unavailable => $this->failPayment($booking, BusinessRuleException::paymentUnavailable()),
        };
    }

    /** Nothing was paid: give the spots back, then report why. */
    private function failPayment(Booking $booking, BusinessRuleException $reason): never
    {
        $this->releaseSpots($booking, BookingStatus::Failed);

        throw $reason;
    }

    /**
     * Remembers the Checkout Session, so Stripe's webhook can find the
     * booking and the traveler can return to an unfinished payment.
     *
     * Stored before the URL is given to the browser, so a webhook (which
     * needs the traveler to have paid on that page) always finds it.
     */
    private function awaitCheckout(Booking $booking, string $sessionId, string $checkoutUrl): Booking
    {
        return DB::transaction(function () use ($booking, $sessionId, $checkoutUrl) {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'payment_session_id' => $sessionId,
                'checkout_url' => $checkoutUrl,
            ])->save();

            return $locked;
        });
    }

    /*
    | Stripe's webhook: the Checkout Session was paid (Exercise 9).
    |
    | Public on purpose: StripeWebhookController calls it after verifying the
    | signature. Returns the booking only when this call confirmed it, so the
    | caller sends the confirmation e-mail exactly once. Returns null when
    | there is nothing to announce:
    |   - no booking has this session (another app on the same Stripe
    |     account, or a session we never stored);
    |   - the amount or currency differ from the booking's total: never
    |     confirm a booking for less than its price (logged as an error);
    |   - the payment was already recorded (Stripe delivers "at least once");
    |   - the booking had expired: it is refunded to the wallet instead.
    */
    public function confirmCheckout(string $sessionId, string $paymentReference, int $amountCents, string $currency): ?Booking
    {
        // total_cents never changes after the booking is created, so it can
        // be compared before taking the lock.
        $booking = Booking::query()->where('payment_session_id', $sessionId)->first();

        if ($booking === null) {
            Log::info('Stripe reported a payment for an unknown Checkout Session; ignored.', ['session' => $sessionId]);

            return null;
        }

        if ($amountCents !== $booking->total_cents || strtolower($currency) !== strtolower(config('payments.currency'))) {
            Log::error('Stripe reported a payment that does not match the booking; not confirmed.', [
                'booking' => $booking->reference,
                'expected' => [$booking->total_cents, config('payments.currency')],
                'reported' => [$amountCents, $currency],
            ]);

            return null;
        }

        return $this->settlePayment($booking, $paymentReference);
    }

    /*
    | Stripe's webhook: the Checkout Session expired unpaid. The spots and the
    | discount use go back, like a declined card. Locked and re-checked, so a
    | repeated event (or a race with bookings:expire-pending) releases them
    | only once.
    */
    public function expireCheckout(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId) {
            /** @var Booking|null $locked */
            $locked = Booking::query()->where('payment_session_id', $sessionId)->lockForUpdate()->first();

            if ($locked?->status === BookingStatus::Pending) {
                $this->releaseSpots($locked, BookingStatus::Failed);
            }
        });
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
            $locked->load('departure', 'user.wallet');

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
    | See D18 in docs/decisions.md. With Stripe, a payment reported after
    | this expiry is credited by confirmCheckout() (D26).
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
     * Locks the departure, checks it can take `$spots` more travelers, prices
     * the booking and claims the spots (and the discount use).
     *
     * Two locks, always in this order (one order everywhere means no
     * deadlocks):
     *   1. the tour, FOR SHARE: its status and price cannot change until we
     *      commit (TourService::save() takes FOR UPDATE and waits), yet
     *      bookings for *other* departures of the same tour do not block
     *      each other;
     *   2. the departure, FOR UPDATE: two bookings of the same date run one
     *      after the other, so the second one sees the first one's spots.
     *
     * Must be called inside a transaction, otherwise the locks are useless.
     * The returned departure has its `tour` relation set.
     *
     * @return array{0: Departure, 1: Quote}
     */
    private function reserveSpots(User $user, Departure $departure, int $spots, ?string $discountCode): array
    {
        /** @var Tour $tour */
        $tour = Tour::query()->whereKey($departure->tour_id)->sharedLock()->firstOrFail();
        $tour->load('operator.operatorProfile');

        /** @var Departure $locked */
        $locked = Departure::query()->whereKey($departure->getKey())->lockForUpdate()->firstOrFail();
        $locked->setRelation('tour', $tour);

        $bookable = $tour->status === TourStatus::Published
            && $tour->operator->operatorProfile?->status === OperatorStatus::Approved
            && ! $locked->hasStarted();

        if (! $bookable) {
            throw BusinessRuleException::tourNotBookable();
        }

        if ($locked->available_spots < $spots) {
            throw BusinessRuleException::notEnoughSpots($locked->available_spots);
        }

        $quote = $this->pricing->quote($tour, $spots, $discountCode, $user, lockDiscount: true);

        // increment() issues `UPDATE departures SET booked_spots = booked_spots + ?`.
        // The CHECK constraint (booked_spots <= capacity) guards it as well.
        $locked->increment('booked_spots', $spots);
        $quote->discount?->increment('redemptions_count');

        return [$locked, $quote];
    }

    private function createBooking(User $user, Departure $departure, Quote $quote, PaymentMethod $method, BookingStatus $status): Booking
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
        // Both from the locked departure: the composite foreign key requires
        // tour_id to be the departure's tour.
        $booking->departure()->associate($departure);
        $booking->tour()->associate($departure->tour);
        $booking->discount()->associate($quote->discount);
        $booking->save();

        return $booking;
    }

    /**
     * Confirms a card booking right after the gateway answered "paid"
     * (the fake gateway, or a free booking).
     *
     * @throws BusinessRuleException when the booking expired during the charge
     */
    private function markPaid(Booking $booking, ?string $paymentReference): Booking
    {
        $confirmed = $this->settlePayment($booking, $paymentReference);

        // Nobody else settles a booking during this request, so null here
        // means it expired and was refunded. Thrown after the transaction,
        // so the wallet credit is committed.
        if ($confirmed === null) {
            throw BusinessRuleException::bookingExpired();
        }

        return $confirmed;
    }

    /**
     * Records that a pending card booking was paid. Returns the booking if
     * this call confirmed it, null otherwise.
     *
     * The booking is locked and re-checked first:
     *   - payment_reference already set: this payment was recorded before
     *     (a repeated webhook). Nothing changes. Without this check a
     *     repeat would look like the late case below and pay a second
     *     refund (the unique index on payment_reference backs this up);
     *   - no longer pending: the payment took longer than the pending
     *     timeout, so bookings:expire-pending may already have marked it
     *     failed and given its spots to someone else. Confirming it then
     *     would overbook the departure. Instead the booking stays failed and,
     *     because we know the card was charged, the amount is credited to the
     *     traveler's wallet (the same travel-credit policy as cancel(), D18).
     *     The payment reference is stored so that a repeat is recognised;
     *   - still pending: confirmed.
     */
    private function settlePayment(Booking $booking, ?string $paymentReference): ?Booking
    {
        return DB::transaction(function () use ($booking, $paymentReference) {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->payment_reference !== null) {
                return null;
            }

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

                $locked->forceFill(['payment_reference' => $paymentReference])->save();

                Log::warning('A card payment succeeded after its booking expired; refunded to the wallet.', [
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
    }

    /** Gives the spots (and the discount use) back and records the new status. */
    private function releaseSpots(Booking $booking, BookingStatus $newStatus): void
    {
        DB::transaction(function () use ($booking, $newStatus) {
            Departure::query()->whereKey($booking->departure_id)->lockForUpdate()->firstOrFail()
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
