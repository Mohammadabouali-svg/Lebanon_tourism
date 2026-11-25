<?php

namespace App\Http\Resources;

use App\Enums\TourStatus;
use App\Models\Departure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/*
| One date of a tour (Exercise 10, D23).
|
| Needs the departure's `tour` relation loaded: ends_on comes from the
| tour's duration and is_bookable from its status. Tour::departures() sets
| it without a query (chaperone); elsewhere, eager-load `departure.tour`.
|
| booked_spots is public, as it was on tours before: it is the same
| information as available_spots.
|
| @mixin Departure
*/
class DepartureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'capacity' => $this->capacity,
            'booked_spots' => $this->booked_spots,
            'available_spots' => $this->available_spots,
            // A display hint only: BookingService re-checks everything under a
            // lock (and also the operator's approval, which this does not).
            'is_bookable' => $this->tour->status === TourStatus::Published
                && ! $this->hasStarted()
                && $this->available_spots > 0,
            'bookings_count' => $this->whenCounted('bookings'),
        ];
    }
}
