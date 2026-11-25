<?php

namespace Tests\Feature\Operator;

use App\Enums\BookingStatus;
use App\Enums\OperatorStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Operators manage the dates their tours run on (Exercise 10, D23).
|
| Access: the operator route group decides "approved operator"; the policy
| (TourPolicy::update, also behind DeparturePolicy) decides "your tour".
| Rules that need the current row (booked spots, started) are checked by
| DepartureService under a lock on the departure.
*/
class DepartureManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Tour $tour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        $this->tour = Tour::factory()->for($this->operator, 'operator')->create();
    }

    private function inDays(int $days): string
    {
        return CarbonImmutable::today()->addDays($days)->toDateString();
    }

    private function departure(array $attributes = []): Departure
    {
        return Departure::factory()->for($this->tour)->create($attributes);
    }

    // --- Creating ---------------------------------------------------------------

    public function test_an_operator_adds_a_departure_to_their_tour(): void
    {
        $this->actingAs($this->operator)
            ->postJson("/api/operator/tours/{$this->tour->id}/departures", ['starts_on' => $this->inDays(10), 'capacity' => 14])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_on', $this->inDays(10))
            ->assertJsonPath('data.0.available_spots', 14);

        $departure = Departure::sole();
        $this->assertTrue($departure->tour->is($this->tour));
        $this->assertSame([14, 0], [$departure->capacity, $departure->booked_spots]);
    }

    public function test_an_operator_adds_a_weekly_series_of_departures(): void
    {
        // "The same tour every Saturday": one request, one date per week.
        $first = CarbonImmutable::today()->next(CarbonImmutable::SATURDAY);

        $this->actingAs($this->operator)
            ->postJson("/api/operator/tours/{$this->tour->id}/departures", [
                'starts_on' => $first->toDateString(),
                'capacity' => 10,
                'weeks' => 4,
            ])
            ->assertCreated()
            ->assertJsonCount(4, 'data');

        $this->assertSame(
            [0, 7, 14, 21],
            Departure::query()->orderBy('starts_on')->get()
                ->map(fn (Departure $departure) => (int) $first->diffInDays($departure->starts_on))->all(),
        );
        $this->assertSame(4, Departure::query()->where('capacity', 10)->whereRaw('EXTRACT(ISODOW FROM starts_on) = 6')->count());
    }

    public function test_the_client_cannot_set_booked_spots_or_choose_the_tour(): void
    {
        $otherTour = Tour::factory()->create();

        $this->actingAs($this->operator)
            ->postJson("/api/operator/tours/{$this->tour->id}/departures", [
                'starts_on' => $this->inDays(10),
                'capacity' => 10,
                'booked_spots' => 10,
                'tour_id' => $otherTour->id,
            ])
            ->assertCreated();

        $departure = Departure::sole();
        $this->assertSame(0, $departure->booked_spots);
        $this->assertSame($this->tour->id, $departure->tour_id);
    }

    public function test_a_new_departure_must_start_after_today(): void
    {
        $this->actingAs($this->operator)
            ->postJson("/api/operator/tours/{$this->tour->id}/departures", ['starts_on' => $this->inDays(0), 'capacity' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_on');

        $this->assertSame(0, Departure::count());
    }

    public function test_capacity_and_series_length_are_limited(): void
    {
        $this->actingAs($this->operator);
        $url = "/api/operator/tours/{$this->tour->id}/departures";

        $this->postJson($url, ['starts_on' => $this->inDays(10), 'capacity' => 0])->assertJsonValidationErrors('capacity');
        $this->postJson($url, ['starts_on' => $this->inDays(10), 'capacity' => 501])->assertJsonValidationErrors('capacity');
        $this->postJson($url, ['starts_on' => $this->inDays(10), 'capacity' => 10, 'weeks' => 0])->assertJsonValidationErrors('weeks');
        $this->postJson($url, ['starts_on' => $this->inDays(10), 'capacity' => 10, 'weeks' => 27])->assertJsonValidationErrors('weeks');

        $this->assertSame(0, Departure::count());
    }

    public function test_a_series_that_hits_an_existing_date_is_refused_as_a_whole(): void
    {
        $this->departure(['starts_on' => $this->inDays(24)]);

        $this->actingAs($this->operator)
            ->postJson("/api/operator/tours/{$this->tour->id}/departures", ['starts_on' => $this->inDays(10), 'capacity' => 10, 'weeks' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on' => 'already has a departure on '.$this->inDays(24)]);

        // Nothing of the series was created: no half-created series.
        $this->assertSame(1, Departure::count());
    }

    // --- Listing ----------------------------------------------------------------

    public function test_an_operator_lists_all_departures_of_their_tour_with_booking_counts(): void
    {
        $past = $this->departure(['starts_on' => $this->inDays(-5)]);
        $booked = $this->departure(['starts_on' => $this->inDays(5), 'capacity' => 8]);
        Booking::factory()->count(2)->for($booked)->create();
        Departure::factory()->create(); // another tour's

        $this->actingAs($this->operator)
            ->getJson("/api/operator/tours/{$this->tour->id}/departures")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $past->id)
            ->assertJsonPath('data.1.id', $booked->id)
            ->assertJsonPath('data.1.bookings_count', 2)
            // fresh(): duration_days is computed by the database.
            ->assertJsonPath('data.1.ends_on', $booked->starts_on->addDays($this->tour->fresh()->duration_days - 1)->toDateString());
    }

    // --- Changing ---------------------------------------------------------------

    public function test_an_operator_changes_the_date_and_capacity_of_an_unbooked_departure(): void
    {
        $departure = $this->departure(['starts_on' => $this->inDays(10), 'capacity' => 10]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(11), 'capacity' => 6])
            ->assertOk()
            ->assertJsonPath('data.starts_on', $this->inDays(11))
            ->assertJsonPath('data.capacity', 6);

        $this->assertSame([$this->inDays(11), 6], [$departure->fresh()->starts_on->toDateString(), $departure->fresh()->capacity]);
    }

    public function test_capacity_cannot_drop_below_the_spots_already_booked(): void
    {
        $departure = $this->departure(['starts_on' => $this->inDays(10), 'capacity' => 10, 'booked_spots' => 7]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(10), 'capacity' => 6])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'capacity_below_bookings');

        $this->assertSame(10, $departure->fresh()->capacity);
    }

    public function test_the_date_of_a_booked_departure_cannot_change(): void
    {
        // Travelers booked that date; moving it is not the operator's call alone.
        $departure = $this->departure(['starts_on' => $this->inDays(10), 'capacity' => 10, 'booked_spots' => 2]);
        Booking::factory()->count(2)->for($departure)->create();

        $this->actingAs($this->operator)
            ->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(12), 'capacity' => 12])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'departure_has_bookings');

        $this->assertSame([$this->inDays(10), 10], [$departure->fresh()->starts_on->toDateString(), $departure->fresh()->capacity]);
    }

    public function test_the_date_cannot_change_even_when_its_only_bookings_were_cancelled(): void
    {
        // A refunded booking is financial history: it must keep the date it was for.
        $departure = $this->departure(['starts_on' => $this->inDays(10), 'capacity' => 10]);
        Booking::factory()->for($departure)->create(['status' => BookingStatus::Cancelled]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(12), 'capacity' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'departure_has_bookings');

        $this->assertSame($this->inDays(10), $departure->fresh()->starts_on->toDateString());
    }

    public function test_a_booked_departure_can_still_get_more_capacity(): void
    {
        $departure = $this->departure(['starts_on' => $this->inDays(10), 'capacity' => 10, 'booked_spots' => 2]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(10), 'capacity' => 16])
            ->assertOk();

        $this->assertSame(16, $departure->fresh()->capacity);
    }

    public function test_a_departure_cannot_be_moved_onto_another_departures_date(): void
    {
        $this->departure(['starts_on' => $this->inDays(10)]);
        $departure = $this->departure(['starts_on' => $this->inDays(17)]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(10), 'capacity' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_on');
    }

    // --- Deleting ---------------------------------------------------------------

    public function test_a_departure_without_bookings_can_be_deleted(): void
    {
        $departure = $this->departure();

        $this->actingAs($this->operator)
            ->deleteJson("/api/operator/departures/{$departure->id}")
            ->assertNoContent();

        $this->assertModelMissing($departure);
    }

    public function test_a_departure_with_bookings_cannot_be_deleted_even_cancelled_ones(): void
    {
        // A cancelled booking is financial history (a refund): it must keep its date.
        $departure = $this->departure();
        Booking::factory()->for($departure)->create(['status' => BookingStatus::Cancelled]);

        $this->actingAs($this->operator)
            ->deleteJson("/api/operator/departures/{$departure->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'departure_has_bookings');

        $this->assertModelExists($departure);
    }

    public function test_a_departure_that_has_started_cannot_be_changed_or_deleted(): void
    {
        $departure = $this->departure(['starts_on' => $this->inDays(-1)]);
        $this->actingAs($this->operator);

        $this->putJson("/api/operator/departures/{$departure->id}", ['starts_on' => $this->inDays(5), 'capacity' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'departure_started');
        $this->deleteJson("/api/operator/departures/{$departure->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'departure_started');

        $this->assertSame($this->inDays(-1), $departure->fresh()->starts_on->toDateString());
    }

    // --- Access -----------------------------------------------------------------

    public function test_an_operator_cannot_touch_another_operators_departures(): void
    {
        $theirs = Departure::factory()->create(['starts_on' => $this->inDays(10), 'capacity' => 10]);
        $this->actingAs($this->operator);

        $this->getJson("/api/operator/tours/{$theirs->tour_id}/departures")->assertForbidden();
        $this->postJson("/api/operator/tours/{$theirs->tour_id}/departures", ['starts_on' => $this->inDays(20), 'capacity' => 5])->assertForbidden();
        $this->putJson("/api/operator/departures/{$theirs->id}", ['starts_on' => $this->inDays(10), 'capacity' => 1])->assertForbidden();
        $this->deleteJson("/api/operator/departures/{$theirs->id}")->assertForbidden();

        $this->assertSame(1, Departure::count());
        $this->assertSame(10, $theirs->fresh()->capacity);
    }

    public function test_unknown_tours_and_departures_are_not_found(): void
    {
        $this->actingAs($this->operator);

        $this->getJson('/api/operator/tours/999999/departures')->assertNotFound();
        $this->deleteJson('/api/operator/departures/999999')->assertNotFound();
    }

    public function test_only_approved_operators_may_manage_departures(): void
    {
        $departure = $this->departure();
        $url = "/api/operator/departures/{$departure->id}";

        $this->deleteJson($url)->assertUnauthorized();
        $this->actingAs($this->traveler())->deleteJson($url)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->deleteJson($url)->assertForbidden();

        // The tour's own operator, while waiting for approval again.
        $this->operator->operatorProfile->forceFill(['status' => OperatorStatus::Pending])->save();
        $this->actingAs($this->operator)->deleteJson($url)->assertForbidden();

        $this->assertModelExists($departure);
    }
}
