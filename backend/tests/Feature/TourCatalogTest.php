<?php

namespace Tests\Feature;

use App\Enums\OperatorStatus;
use App\Models\City;
use App\Models\Tour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TourCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_list_hides_drafts_past_tours_and_suspended_operators(): void
    {
        $visible = Tour::factory()->create(['title' => 'Visible']);
        Tour::factory()->draft()->create();
        Tour::factory()->started()->create();
        $suspended = Tour::factory()->create();
        $suspended->operator->operatorProfile->forceFill(['status' => OperatorStatus::Suspended])->save();

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        $this->getJson("/api/tours/{$suspended->id}")->assertNotFound();
    }

    // The "Sold out" badge on tour cards (exercise 3) relies on these two
    // facts: a full tour stays in the public list, and the API says it
    // cannot be booked. The frontend decides nothing; it only displays them.
    public function test_a_sold_out_tour_is_still_listed_but_not_bookable(): void
    {
        $tour = Tour::factory()->create(['capacity' => 8, 'booked_spots' => 8]);

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tour->id)
            ->assertJsonPath('data.0.available_spots', 0)
            ->assertJsonPath('data.0.is_bookable', false);
    }

    public function test_a_tour_with_spots_left_is_bookable(): void
    {
        $tour = Tour::factory()->create(['capacity' => 8, 'booked_spots' => 7]);

        $this->getJson('/api/tours')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tour->id)
            ->assertJsonPath('data.0.available_spots', 1)
            ->assertJsonPath('data.0.is_bookable', true);
    }

    public function test_the_public_tour_page_shows_the_meeting_point(): void
    {
        $tour = Tour::factory()->create(['meeting_point' => 'Byblos harbour, next to the fish market']);

        $this->getJson("/api/tours/{$tour->id}")
            ->assertOk()
            ->assertJsonPath('data.meeting_point', 'Byblos harbour, next to the fish market');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Tour::factory()->create(['title' => 'Save 100% on nothing']);
        Tour::factory()->create(['title' => 'Ordinary tour']);

        $this->getJson('/api/tours?search='.urlencode('100%'))->assertJsonCount(1, 'data');
        // Unescaped, "%" alone would match every tour.
        $this->getJson('/api/tours?search='.urlencode('%'))->assertJsonCount(1, 'data');
    }

    public function test_search_also_matches_the_city_name(): void
    {
        $byblos = City::factory()->create(['name' => 'Byblos']);
        Tour::factory()->for($byblos)->create(['title' => 'Old souk walk']);
        Tour::factory()->create(['title' => 'Somewhere else']);

        $this->getJson('/api/tours?search=byb')->assertJsonCount(1, 'data');
    }

    public function test_duration_buckets_have_no_gaps(): void
    {
        $start = CarbonImmutable::today()->addDays(10);
        foreach ([1, 3, 4, 7, 8, 14, 15, 21] as $days) {
            Tour::factory()->create([
                'title' => "{$days} days",
                'starts_on' => $start->toDateString(),
                'ends_on' => $start->addDays($days - 1)->toDateString(),
            ]);
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
        Tour::factory()->create(['price_cents' => 9_000]);
        Tour::factory()->create(['price_cents' => 3_000]);
        Tour::factory()->create(['price_cents' => 20_000]);

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
