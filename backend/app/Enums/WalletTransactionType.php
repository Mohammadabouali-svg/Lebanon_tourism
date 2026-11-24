<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    /** Money in: admin top-up, refund of a cancelled booking. */
    case Credit = 'credit';

    /** Money out: paying for a booking. */
    case Debit = 'debit';
}
