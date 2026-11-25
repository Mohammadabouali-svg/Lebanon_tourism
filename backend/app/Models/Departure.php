<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
| One date a tour runs on, with its own capacity (Exercise 10, D23).
|
| Not fillable on purpose:
|   tour_id      - always the tour from the URL, set by DepartureService
|   booked_spots - only BookingService changes it, inside a transaction
*/
#[Fillable(['starts_on', 'capacity'])]
class Departure extends Model
{
    use HasFactory;

    /** In-memory defaults, mirroring the database (see User::$attributes). */
    protected $attributes = [
        'booked_spots' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'capacity' => 'integer',
            'booked_spots' => 'integer',
        ];
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Departures that have not started yet (the same "after today" rule as before). */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->whereDate('starts_on', '>', today());
    }

    protected function availableSpots(): Attribute
    {
        return Attribute::get(fn () => $this->capacity - $this->booked_spots);
    }

    /**
     * The last day, from the tour's duration. Needs the tour loaded: strict
     * mode throws on lazy loading, so callers eager-load `tour`.
     */
    protected function endsOn(): Attribute
    {
        return Attribute::get(fn (): CarbonImmutable => $this->starts_on->addDays($this->tour->duration_days - 1));
    }

    /** Free cancellation and booking both end when the departure starts. */
    public function hasStarted(): bool
    {
        return ! $this->starts_on->isFuture();
    }
}
