<?php

namespace App\Support;

/*
| The API sends and receives money as integer cents (e.g. 12999 = $129.99).
| The React app formats amounts for display. This helper exists for the few
| places where the server itself produces human-readable text (CSV exports,
| e-mails).
*/
final class Money
{
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s$%s.%02d', $sign, number_format(intdiv($cents, 100)), $cents % 100);
    }
}
