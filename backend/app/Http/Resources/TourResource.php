<?php

namespace App\Http\Resources;

use App\Enums\TourStatus;
use App\Models\Tour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tour */
class TourResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            // Public on purpose: it is a public place, needed before booking
            // to decide whether the tour is practical to reach.
            'meeting_point' => $this->meeting_point,
            'price_cents' => $this->price_cents,
            'capacity' => $this->capacity,
            'booked_spots' => $this->booked_spots,
            'available_spots' => $this->available_spots,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'duration_days' => $this->duration_days,
            'status' => $this->status,
            'is_bookable' => $this->status === TourStatus::Published
                && ! $this->hasStarted()
                && $this->available_spots > 0,
            'image_url' => $this->image_url,

            'city' => CityResource::make($this->whenLoaded('city')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'operator' => $this->whenLoaded('operator', fn () => [
                'company_name' => $this->operator->operatorProfile?->company_name,
            ]),
            'itinerary' => ItineraryItemResource::collection($this->whenLoaded('itineraryItems')),

            // Filled by withAvg()/withCount() in the controllers.
            'rating_average' => $this->whenHas('reviews_avg_rating', fn () => $this->reviews_avg_rating === null
                ? null
                : round((float) $this->reviews_avg_rating, 1)),
            'reviews_count' => $this->whenCounted('reviews'),
            'bookings_count' => $this->whenCounted('bookings'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
