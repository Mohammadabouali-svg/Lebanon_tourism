<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
| No #[Fillable] attribute here: bookings are only ever created by
| BookingService, which sets every column explicitly. Nothing coming from a
| request can be mass-assigned into a booking.
*/
class Booking extends Model
{
    use HasFactory;

    /** In-memory defaults, mirroring the database (see User::$attributes). */
    protected $attributes = [
        'discount_id' => null,
        'discount_cents' => 0,
        'payment_reference' => null,
        'paid_at' => null,
        'cancelled_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'payment_method' => PaymentMethod::class,
            'spots' => 'integer',
            'unit_price_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** Travelers may cancel a confirmed booking until the tour starts. */
    public function isCancellable(): bool
    {
        return $this->status === BookingStatus::Confirmed && ! $this->tour->hasStarted();
    }
}
