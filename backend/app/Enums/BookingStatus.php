<?php

namespace App\Enums;

/*
| Lifecycle of a booking:
|
|   wallet payment:  ─────────────────────────────► confirmed ──► cancelled
|   card payment:    pending ──(gateway approves)──► confirmed ──► cancelled
|                       │   (Stripe: the signed webhook reports the payment)
|                       ├─────(gateway declines,
|                       │      Stripe unreachable,
|                       │      Checkout Session expired)──► failed
|                       └──(still pending > 15 min, 35 with Stripe,
|                           bookings:expire-pending)──► failed
*/
enum BookingStatus: string
{
    /** Spots are held while the card payment is processed. */
    case Pending = 'pending';

    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';

    /** The payment was declined or not made, or the booking expired while pending. The held spots were released. */
    case Failed = 'failed';

    /**
     * Statuses that occupy spots on the tour and count as a discount use.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Confirmed];
    }
}
