<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/*
| Thrown when a request is well-formed but breaks a business rule:
| "the tour is sold out", "your wallet balance is too low", ...
|
| Validation errors (wrong types, missing fields) are handled by Form
| Requests. This exception is for rules that need the database to decide.
|
| Laravel calls render() automatically and returns HTTP 422 with a stable
| machine-readable `code`, so the frontend can react to specific cases.
| ShouldntReport keeps these expected errors out of the error log.
*/
class BusinessRuleException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function tourNotBookable(): self
    {
        return new self('This tour is not open for booking.', 'tour_not_bookable');
    }

    public static function notEnoughSpots(int $available): self
    {
        return new self(
            $available === 0
                ? 'This tour is sold out.'
                : "Only {$available} spot(s) left on this tour.",
            'not_enough_spots',
        );
    }

    public static function insufficientFunds(): self
    {
        return new self('Your wallet balance is too low for this booking.', 'insufficient_funds');
    }

    public static function invalidDiscount(string $reason): self
    {
        return new self($reason, 'invalid_discount');
    }

    public static function paymentDeclined(?string $reason): self
    {
        return new self($reason ?? 'Your payment was declined.', 'payment_declined');
    }

    /** The card was charged after the booking had already expired (see BookingService::markPaid). */
    public static function bookingExpired(): self
    {
        return new self(
            'Your payment took too long and the booking expired. The amount was added to your wallet as travel credit.',
            'booking_expired',
        );
    }

    public static function bookingNotCancellable(): self
    {
        return new self('This booking can no longer be cancelled.', 'booking_not_cancellable');
    }

    public static function capacityBelowBookings(int $booked): self
    {
        return new self("Capacity cannot be lower than the {$booked} spot(s) already booked.", 'capacity_below_bookings');
    }

    public static function tourHasBookings(): self
    {
        return new self('A tour with bookings cannot be deleted. Archive it instead.', 'tour_has_bookings');
    }

    /** E.g. deleting a city that tours still point to. */
    public static function stillInUse(string $thing, string $advice = ''): self
    {
        return new self(trim("This {$thing} is still in use and cannot be deleted. {$advice}"), 'still_in_use');
    }

    public static function cannotSuspendSelf(): self
    {
        return new self('You cannot suspend your own account.', 'cannot_suspend_self');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], 422);
    }
}
