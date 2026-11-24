<?php

namespace App\Enums;

enum DiscountType: string
{
    /** `value` is an amount in cents, e.g. 1500 = $15 off. */
    case Fixed = 'fixed';

    /** `value` is a percentage from 1 to 100. */
    case Percent = 'percent';
}
