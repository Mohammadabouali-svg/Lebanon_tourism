<?php

namespace App\Exports;

use App\Models\Booking;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| CSV export of bookings, used by operators (their tours) and admins (all).
| The caller passes an already-scoped query, so this class cannot leak data:
| it only exports what the caller was allowed to see.
*/
final class BookingsExport
{
    public static function download(Builder $bookings, string $filename): StreamedResponse
    {
        $rows = $bookings
            ->with(['tour:id,title', 'departure:id,starts_on', 'user:id,name,email,phone', 'discount:id,code'])
            ->orderBy('id')
            // lazy() fetches 1000 rows at a time instead of everything at once.
            ->lazy(1000)
            ->map(fn (Booking $booking) => [
                $booking->reference,
                $booking->created_at->toDateTimeString(),
                $booking->tour->title,
                $booking->departure->starts_on->toDateString(),
                $booking->user->name,
                $booking->user->email,
                $booking->user->phone,
                $booking->spots,
                Money::format($booking->subtotal_cents),
                $booking->discount?->code,
                Money::format($booking->discount_cents),
                Money::format($booking->total_cents),
                $booking->payment_method->value,
                $booking->status->value,
            ]);

        return CsvExport::download($filename, [
            'Reference', 'Booked at (UTC)', 'Tour', 'Departure', 'Traveler', 'E-mail', 'Phone',
            'Spots', 'Subtotal', 'Discount code', 'Discount', 'Total', 'Payment method', 'Status',
        ], $rows);
    }
}
