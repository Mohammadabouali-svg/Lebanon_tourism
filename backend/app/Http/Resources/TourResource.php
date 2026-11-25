<?php

namespace App\Http\Resources;

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
            // The length of every departure; dates and spots are per departure
            // (Exercise 10, D23).
            'duration_days' => $this->duration_days,
            'status' => $this->status,
            // The admin moderation lock (D19). Safe to show publicly: the
            // tours_moderated_means_archived CHECK keeps it null on every
            // published tour, so only operators and admins ever see a value.
            'moderated_at' => $this->moderated_at?->toIso8601String(),
            'image_url' => $this->image_url,

            'city' => CityResource::make($this->whenLoaded('city')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'operator' => $this->whenLoaded('operator', fn () => [
                'company_name' => $this->operator->operatorProfile?->company_name,
            ]),
            'itinerary' => ItineraryItemResource::collection($this->whenLoaded('itineraryItems')),

            // When the controller loaded `upcomingDepartures` (soonest first).
            // DepartureResource never embeds its tour, so this cannot recurse.
            'upcoming_departures' => DepartureResource::collection($this->whenLoaded('upcomingDepartures')),
            // null (not {}) when there is none.
            'next_departure' => $this->whenLoaded('upcomingDepartures', fn () => $this->upcomingDepartures->isEmpty()
                ? null
                : DepartureResource::make($this->upcomingDepartures->first())),
            // The "Sold out" badge (Exercise 3): dates are coming, none has a spot.
            // A tour with no upcoming departure is not "sold out", just not running.
            'is_sold_out' => $this->whenLoaded('upcomingDepartures', fn () => $this->upcomingDepartures->isNotEmpty()
                && $this->upcomingDepartures->every(fn ($departure) => $departure->available_spots === 0)),

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
