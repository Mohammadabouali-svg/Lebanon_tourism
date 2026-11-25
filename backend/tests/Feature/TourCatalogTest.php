<?php

namespace Tests\Feature;

use App\Enums\OperatorStatus;
use App\Models\City;
use App\Models\Departure;
use App\Models\Tour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| The public catalogue. Since Exercise 10 a tour is listed while it has at
| least one upcoming departure, and the list shows each tour's upcoming
| departures (D23).
*/
class TourCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function inDays(int $days): string
    {
        return CarbonImmutable::today()->addDays($days)->toDateString();
    }

    public function test_the_public_list_hides_drafts_past_tours_and_suspended_operators(): void
    {
        $visible = Tour::factory()->withDeparture()->create(['title' => 'Visible']);
        Tour::factory()->draft()->withDeparture()->create();
        Tour::factory()->has(Departure::factory()->started())->create();
        Tour::factory()->create(); // no departure at all
        $suspended = Tour::factory()->withDeparture()->create();
        $suspended->operator->operatorProfile->forceFill(['status' => OperatorStatus::Suspended])->save();

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        $this->getJson("/api/tours/{$suspended->id}")->assertNotFound();
    }

    public function test_a_tour_with_a_past_and_an_upcoming_departure_is_listed_with_the_upcoming_one_only(): void
    {
        $tour = Tour::factory()->create();
        Departure::factory()->for($tour)->started()->create();
        $next = Departure::factory()->for($tour)->create(['starts_on' => $this->inDays(9)]);

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.upcoming_departures')
            ->assertJsonPath('data.0.next_departure.id', $next->id)
            ->assertJsonPath('data.0.next_departure.starts_on', $this->inDays(9));
    }

    public function test_the_tour_page_lists_its_upcoming_departures_soonest_first(): void
    {
        $tour = Tour::factory()->create(['duration_days' => 2]);
        $later = Departure::factory()->for($tour)->create(['starts_on' => $this->inDays(14), 'capacity' => 10, 'booked_spots' => 4]);
        $sooner = Departure::factory()->for($tour)->create(['starts_on' => $this->inDays(7)]);
        Departure::factory()->for($tour)->started()->create();

        $this->getJson("/api/tours/{$tour->id}")
            ->assertOk()
            ->assertJsonPath('data.upcoming_departures.*.id', [$sooner->id, $later->id])
            ->assertJsonPath('data.upcoming_departures.1.ends_on', $this->inDays(15))
            ->assertJsonPath('data.upcoming_departures.1.available_spots', 6)
            ->assertJsonPath('data.upcoming_departures.1.is_bookable', true);
    }

    public function test_the_tour_no_longer_has_its_own_dates_or_capacity(): void
    {
        // They moved to departures; a stale copy on the tour would contradict them.
        $tour = Tour::factory()->withDeparture()->create();

        $data = $this->getJson("/api/tours/{$tour->id}")->assertOk()->json('data');

        foreach (['starts_on', 'ends_on', 'capacity', 'booked_spots', 'available_spots', 'is_bookable'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
    }

    // The "Sold out" badge on tour cards (exercise 3) relies on these facts:
    // a full tour stays in the public list, and the API says it is sold out.
    // Since Exercise 10 "sold out" means: no upcoming departure has a spot.
    public function test_a_tour_whose_upcoming_departures_are_all_full_is_listed_as_sold_out(): void
    {
        $tour = Tour::factory()->create();
        Departure::factory()->for($tour)->create(['capacity' => 8, 'booked_spots' => 8]);
        Departure::factory()->for($tour)->create(['capacity' => 4, 'booked_spots' => 4]);

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tour->id)
            ->assertJsonPath('data.0.is_sold_out', true)
            ->assertJsonPath('data.0.next_departure.available_spots', 0)
            ->assertJsonPath('data.0.next_departure.is_bookable', false);
    }

    public function test_a_tour_is_not_sold_out_while_one_departure_has_a_spot(): void
    {
        $tour = Tour::factory()->create();
        Departure::factory()->for($tour)->create(['starts_on' => $this->inDays(7), 'capacity' => 8, 'booked_spots' => 8]);
        Departure::factory()->for($tour)->create(['starts_on' => $this->inDays(14), 'capacity' => 8, 'booked_spots' => 7]);

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonPath('data.0.is_sold_out', false)
            ->assertJsonPath('data.0.upcoming_departures.1.available_spots', 1)
            ->assertJsonPath('data.0.upcoming_departures.1.is_bookable', true);
    }

    public function test_soonest_sorts_by_the_next_upcoming_departure(): void
    {
        $late = Tour::factory()->create(['title' => 'Late']);
        Departure::factory()->for($late)->create(['starts_on' => $this->inDays(30)]);
        $soon = Tour::factory()->create(['title' => 'Soon']);
        Departure::factory()->for($soon)->create(['starts_on' => $this->inDays(60)]);
        Departure::factory()->for($soon)->create(['starts_on' => $this->inDays(5)]);
        // A past departure must not count as "soonest".
        $old = Tour::factory()->create(['title' => 'Old']);
        Departure::factory()->for($old)->started()->create();
        Departure::factory()->for($old)->create(['starts_on' => $this->inDays(45)]);

        $this->getJson('/api/tours?sort=soonest')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Soon', 'Late', 'Old']);
    }

    public function test_the_public_tour_page_shows_the_meeting_point(): void
    {
        $tour = Tour::factory()->withDeparture()->create(['meeting_point' => 'Byblos harbour, next to the fish market']);

        $this->getJson("/api/tours/{$tour->id}")
            ->assertOk()
            ->assertJsonPath('data.meeting_point', 'Byblos harbour, next to the fish market');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Tour::factory()->withDeparture()->create(['title' => 'Save 100% on nothing']);
        Tour::factory()->withDeparture()->create(['title' => 'Ordinary tour']);

        $this->getJson('/api/tours?search='.urlencode('100%'))->assertJsonCount(1, 'data');
        // Unescaped, "%" alone would match every tour.
        $this->getJson('/api/tours?search='.urlencode('%'))->assertJsonCount(1, 'data');
    }

    public function test_search_also_matches_the_city_name(): void
    {
        $byblos = City::factory()->create(['name' => 'Byblos']);
        Tour::factory()->for($byblos)->withDeparture()->create(['title' => 'Old souk walk']);
        Tour::factory()->withDeparture()->create(['title' => 'Somewhere else']);

        $this->getJson('/api/tours?search=byb')->assertJsonCount(1, 'data');
    }

    public function test_duration_buckets_have_no_gaps(): void
    {
        foreach ([1, 3, 4, 7, 8, 14, 15, 21] as $days) {
            Tour::factory()->withDeparture()->create(['title' => "{$days} days", 'duration_days' => $days]);
        }

        $count = fn (string $query) => count($this->getJson("/api/tours?{$query}")->json('data'));

        // Every tour falls into exactly one bucket (the original's buckets had gaps).
        $this->assertSame(2, $count('min_days=1&max_days=3'));
        $this->assertSame(2, $count('min_days=4&max_days=7'));
        $this->assertSame(2, $count('min_days=8&max_days=14'));
        $this->assertSame(2, $count('min_days=15'));
    }

    public function test_filters_by_price_and_sorts_by_price(): void
    {
        Tour::factory()->withDeparture()->create(['price_cents' => 9_000]);
        Tour::factory()->withDeparture()->create(['price_cents' => 3_000]);
        Tour::factory()->withDeparture()->create(['price_cents' => 20_000]);

        $prices = $this->getJson('/api/tours?max_price_cents=10000&sort=price_desc')->json('data.*.price_cents');

        $this->assertSame([9_000, 3_000], $prices);
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->getJson('/api/tours?sort=drop_table&per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }
}
