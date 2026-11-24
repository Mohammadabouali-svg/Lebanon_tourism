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
| Not fillable on purpose:
|   operator_id  - always the logged-in operator, set by the controller
|   booked_spots - only BookingService changes it, inside a transaction
|   image_path   - set after the uploaded file was processed
*/
#[Fillable([
    'title', 'description', 'meeting_point', 'city_id', 'category_id', 'price_cents',
    'capacity', 'starts_on', 'ends_on', 'status',
])]
class Tour extends Model
{
    use HasFactory;

    /*
    | In-memory defaults, mirroring the database (see User::$attributes).
    | `duration_days` is computed by Postgres, so TourService refreshes the
    | model after saving to read it back.
    */
    protected $attributes = [
        'booked_spots' => 0,
        'status' => 'draft',
        'image_path' => null,
        'meeting_point' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => TourStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'price_cents' => 'integer',
            'capacity' => 'integer',
            'booked_spots' => 'integer',
            'duration_days' => 'integer',
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

    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->whereDate('starts_on', '>', today());
    }

    // --- Computed attributes ------------------------------------------------

    protected function availableSpots(): Attribute
    {
        return Attribute::get(fn () => $this->capacity - $this->booked_spots);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null);
    }

    public function hasStarted(): bool
    {
        return ! $this->starts_on->isFuture();
    }
}
