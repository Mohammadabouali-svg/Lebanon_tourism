<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Departure;
use App\Models\Tour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| An operator manages the dates a tour runs on (Exercise 10, D23).
|
| Every check that depends on bookings reads the departure row under
| FOR UPDATE, the same lock BookingService takes before counting spots. A
| booking and an operator's change of the same departure therefore run one
| after the other: the capacity can never be lowered below spots that a
| booking claimed a millisecond earlier. (BookingService also takes FOR
| SHARE on the tour first; this class never locks the tour, so the lock
| order cannot form a cycle.)
*/
final class DepartureService
{
    /**
     * Creates one departure per date, all or nothing.
     *
     * @param  list<CarbonImmutable>  $dates
     * @return Collection<int, Departure>
     */
    public function create(Tour $tour, array $dates, int $capacity): Collection
    {
        try {
            $departures = DB::transaction(fn () => new Collection(array_map(
                fn (CarbonImmutable $date) => $tour->departures()->create(['starts_on' => $date, 'capacity' => $capacity]),
                $dates,
            )));
        } catch (UniqueConstraintViolationException) {
            // StoreDepartureRequest checks the dates first; this only happens
            // when two requests add the same date at the same moment. The
            // unique index decided; report it like the validation would.
            throw ValidationException::withMessages(['starts_on' => 'This tour already has a departure on one of these dates.']);
        }

        return $departures->each(fn (Departure $departure) => $departure->setRelation('tour', $tour));
    }

    /** @throws BusinessRuleException */
    public function update(Departure $departure, CarbonImmutable $startsOn, int $capacity): Departure
    {
        return DB::transaction(function () use ($departure, $startsOn, $capacity) {
            /** @var Departure $locked */
            $locked = Departure::query()->whereKey($departure->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasStarted()) {
                throw BusinessRuleException::departureStarted();
            }

            if ($capacity < $locked->booked_spots) {
                throw BusinessRuleException::capacityBelowBookings($locked->booked_spots);
            }

            // Any booking, even a cancelled or failed one: active ones are
            // travelers who would arrive on the old date, and refunded ones are
            // financial history that must keep the date it was for. Raising
            // the capacity of a booked departure is fine; moving it is not.
            if (! $startsOn->equalTo($locked->starts_on) && $locked->bookings()->exists()) {
                throw BusinessRuleException::departureHasBookings('move it to another date');
            }

            $locked->fill(['starts_on' => $startsOn, 'capacity' => $capacity])->save();

            return $locked->setRelation('tour', $departure->tour);
        });
    }

    /** @throws BusinessRuleException */
    public function delete(Departure $departure): void
    {
        DB::transaction(function () use ($departure) {
            /** @var Departure $locked */
            $locked = Departure::query()->whereKey($departure->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasStarted()) {
                throw BusinessRuleException::departureStarted();
            }

            // Any booking, even cancelled or failed: the bookings foreign key
            // restricts deletion anyway, this turns it into a clear message.
            if ($locked->bookings()->exists()) {
                throw BusinessRuleException::departureHasBookings('delete it');
            }

            $locked->delete();
        });
    }
}
