<?php

namespace App\Models;

use App\Enums\OperatorStatus;
use App\Enums\TourStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/*
| Since Exercise 10 a tour has no date and no capacity of its own: it runs on
| departures (Departure), each with its date, capacity and booked_spots
| counter (docs/decisions.md D23). `duration_days` is the length of every
| departure of the tour.
|
| Not fillable on purpose:
|   operator_id  - always the logged-in operator, set by the controller
|   image_path   - set after the uploaded file was processed
|   moderated_at - the admin moderation lock, only TourService::moderate()
|                  sets or clears it (docs/decisions.md D19)
*/
#[Fillable([
    'title', 'description', 'meeting_point', 'city_id', 'category_id', 'price_cents',
    'duration_days', 'status',
])]
class Tour extends Model
{
    use HasFactory;

    /** In-memory defaults, mirroring the database (see User::$attributes). */
    protected $attributes = [
        'status' => 'draft',
        'image_path' => null,
        'meeting_point' => null,
        'moderated_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => TourStatus::class,
            'price_cents' => 'integer',
            'duration_days' => 'integer',
            'moderated_at' => 'immutable_datetime',
        ];
    }

    // --- Relationships ------------------------------------------------------

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function itineraryItems(): HasMany
    {
        return $this->hasMany(ItineraryItem::class)->orderBy('day')->orderBy('id');
    }

    /*
    | chaperone() sets each departure's `tour` relation to this tour when the
    | departures are loaded through it, so Departure::$ends_on (which needs
    | the tour's duration) works without one extra query per departure.
    */
    public function departures(): HasMany
    {
        return $this->hasMany(Departure::class)->orderBy('starts_on')->chaperone();
    }

    /** Departures that have not started yet, soonest first (catalogue, booking pages). */
    public function upcomingDepartures(): HasMany
    {
        return $this->departures()->upcoming();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // --- Query scopes -------------------------------------------------------
    // A scope is a reusable piece of a query: Tour::visibleToPublic()->get().
    // Defining "what the public may see" once means no endpoint can forget a
    // condition (e.g. forget to hide tours of suspended operators).

    #[Scope]
    protected function visibleToPublic(Builder $query): void
    {
        $query->where('status', TourStatus::Published)
            ->whereHas('operator.operatorProfile', function (Builder $profile) {
                $profile->where('status', OperatorStatus::Approved);
            });
    }

    /** Tours with at least one departure that has not started yet. */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->whereHas('departures', fn (Builder $departures) => $departures->upcoming());
    }

    /*
    | Soonest next departure first. A correlated subquery per tour,
    | MIN(starts_on) of its upcoming departures, answered from the
    | departures_one_per_day (tour_id, starts_on) index.
    */
    #[Scope]
    protected function orderByNextDeparture(Builder $query): void
    {
        $query->orderBy(
            Departure::query()
                ->selectRaw('MIN(starts_on)')
                ->whereColumn('departures.tour_id', 'tours.id')
                ->upcoming()
        );
    }

    // --- Computed attributes ------------------------------------------------

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null);
    }
}
