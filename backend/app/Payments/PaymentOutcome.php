<?php

namespace App\Payments;

/** The four answers a gateway can give when a payment starts. See PaymentStart. */
enum PaymentOutcome
{
    case Paid;
    case Declined;
    case Redirect;
    case Unavailable;
}
