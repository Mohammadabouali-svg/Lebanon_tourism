<?php

namespace Tests\Feature\Operator;

use App\Enums\TourStatus;
use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Tour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TourManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->operator = User::factory()->operator()->create();
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        $start = CarbonImmutable::today()->addDays(20);

        return array_merge([
            'title' => 'Qadisha Valley Trek',
            'description' => 'Three days through the Holy Valley.',
            'city_id' => City::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'price_cents' => 14_000,
            'capacity' => 12,
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->addDays(2)->toDateString(),
            'status' => 'published',
            'itinerary' => [
                ['day' => 1, 'title' => 'Descent', 'description' => 'Down to the monastery.'],
                ['day' => 3, 'title' => 'Cedars', 'description' => null],
            ],
        ], $overrides);
    }

    public function test_an_operator_creates_a_tour_with_an_itinerary_and_image(): void
    {
        $this->actingAs($this->operator)
            ->post('/api/operator/tours', $this->validPayload([
                'image' => UploadedFile::fake()->image('cover.png', 1600, 900),
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.duration_days', 3)
            ->assertJsonCount(2, 'data.itinerary');

        $tour = Tour::sole();
        $this->assertTrue($tour->operator->is($this->operator), 'The owner comes from the session, not the request.');
        Storage::disk('public')->assertExists($tour->image_path);
    }

    public function test_the_operator_id_in_the_request_is_ignored(): void
    {
        $victim = User::factory()->operator()->create();

        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload(['operator_id' => $victim->id]))
            ->assertCreated();

        $this->assertTrue(Tour::sole()->operator->is($this->operator));
    }

    public function test_an_operator_can_give_a_tour_a_meeting_point(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload([
                'meeting_point' => 'Main gate of the National Museum, Beirut, 7:30 AM',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.meeting_point', 'Main gate of the National Museum, Beirut, 7:30 AM');

        $this->assertSame('Main gate of the National Museum, Beirut, 7:30 AM', Tour::sole()->meeting_point);
    }

    public function test_the_meeting_point_is_optional(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.meeting_point', null);

        $this->assertNull(Tour::sole()->meeting_point);
    }

    public function test_an_emptied_meeting_point_is_stored_as_null(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->create(['meeting_point' => 'Old souk entrance']);

        // The form sends an empty string when the field is cleared. Laravel's
        // ConvertEmptyStringsToNull middleware must turn it into a real NULL,
        // so "no meeting point" has exactly one representation.
        $this->actingAs($this->operator)
            ->putJson("/api/operator/tours/{$tour->id}", $this->validPayload(['meeting_point' => '']))
            ->assertOk()
            ->assertJsonPath('data.meeting_point', null);

        $this->assertNull($tour->fresh()->meeting_point);
    }

    public function test_a_meeting_point_longer_than_255_characters_is_refused(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload(['meeting_point' => str_repeat('a', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting_point');

        $this->assertDatabaseCount('tours', 0);
    }

    public function test_prices_above_ten_thousand_dollars_are_refused(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload(['price_cents' => 1_000_001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_cents' => 'The price may not be more than $10,000.']);

        $this->assertDatabaseCount('tours', 0);
    }

    public function test_a_price_of_exactly_ten_thousand_dollars_is_accepted(): void
    {
        // The limit is inclusive: $10,000.00 itself is allowed.
        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload(['price_cents' => 1_000_000]))
            ->assertCreated()
            ->assertJsonPath('data.price_cents', 1_000_000);

        $this->assertDatabaseHas('tours', ['price_cents' => 1_000_000]);
    }

    public function test_the_price_limit_also_applies_when_updating_a_tour(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->create(['price_cents' => 14_000]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/tours/{$tour->id}", $this->validPayload(['price_cents' => 1_000_001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_cents');

        $this->assertSame(14_000, $tour->fresh()->price_cents);
    }

    public function test_itinerary_days_must_fit_inside_the_tour(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/operator/tours', $this->validPayload([
                'itinerary' => [['day' => 9, 'title' => 'Too late']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('itinerary.0.day');
    }

    public function test_svg_images_are_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');

        $this->actingAs($this->operator)
            ->post('/api/operator/tours', $this->validPayload(['image' => $svg]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_capacity_cannot_drop_below_the_spots_already_booked(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->create(['capacity' => 10, 'booked_spots' => 6]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/tours/{$tour->id}", $this->validPayload(['capacity' => 5]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'capacity_below_bookings');

        $this->assertSame(10, $tour->fresh()->capacity);
    }

    // --- Admin moderation lock (Exercise 6, D19) ------------------------------

    public function test_an_operator_cannot_republish_a_tour_an_admin_archived(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->moderated()->create(['title' => 'Old title']);
        $lockedAt = $tour->moderated_at;

        $this->actingAs($this->operator)
            ->putJson("/api/operator/tours/{$tour->id}", $this->validPayload(['status' => 'published']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'tour_moderated');

        // Nothing of the save went through, not only the status.
        $tour = $tour->fresh();
        $this->assertSame(TourStatus::Archived, $tour->status);
        $this->assertSame('Old title', $tour->title);
        $this->assertTrue($lockedAt->equalTo($tour->moderated_at));
    }

    public function test_an_operator_can_edit_a_moderated_tour_without_changing_its_status(): void
    {
        // The form always sends a status (TourRequest requires it), so a save
        // that keeps the current status must still work on a locked tour.
        $tour = Tour::factory()->for($this->operator, 'operator')->moderated()->create();

        $this->actingAs($this->operator)
            ->putJson("/api/operator/tours/{$tour->id}", $this->validPayload(['status' => 'archived', 'title' => 'Fixed title']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Fixed title')
            ->assertJsonPath('data.status', 'archived');

        $this->assertNotNull($tour->fresh()->moderated_at);
    }

    public function test_an_operator_can_republish_a_tour_they_archived_themselves(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->create(['status' => TourStatus::Archived]);

        $this->actingAs($this->operator)
            ->putJson("/api/operator/tours/{$tour->id}", $this->validPayload(['status' => 'published']))
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame(TourStatus::Published, $tour->fresh()->status);
    }

    public function test_the_operator_sees_the_lock_on_their_tour(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->moderated()->create();

        $this->actingAs($this->operator)
            ->getJson("/api/operator/tours/{$tour->id}")
            ->assertOk()
            ->assertJsonPath('data.moderated_at', $tour->moderated_at->toIso8601String());
    }

    public function test_a_tour_with_bookings_cannot_be_deleted(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->create();
        Booking::factory()->for($tour)->create();

        $this->actingAs($this->operator)
            ->deleteJson("/api/operator/tours/{$tour->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'tour_has_bookings');

        $this->assertModelExists($tour);
    }

    public function test_bookings_export_is_csv_and_neutralises_formulas(): void
    {
        $tour = Tour::factory()->for($this->operator, 'operator')->create();
        $traveler = User::factory()->create(['name' => '=HYPERLINK("http://evil.example","Click me")']);
        Booking::factory()->for($tour)->for($traveler)->create();

        $response = $this->actingAs($this->operator)->get('/api/operator/bookings/export');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }
}
