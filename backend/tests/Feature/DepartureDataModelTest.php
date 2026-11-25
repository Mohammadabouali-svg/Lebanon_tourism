<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
| The `departures` table (Exercise 10, docs/decisions.md D23): its database
| constraints, and the migrations that moved dates, capacity and bookings
| from tours to departures.
|
| The constraint tests write with the query builder or increment(), i.e.
| around the services, to prove the database refuses bad rows by itself.
*/
class DepartureDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_refuses_overbooking_a_departure(): void
    {
        $departure = Departure::factory()->create(['capacity' => 3]);

        $this->expectException(QueryException::class);
        $departure->increment('booked_spots', 4);
    }

    public function test_the_database_refuses_a_negative_booked_spots_counter(): void
    {
        // Laravel's unsignedSmallInteger is a plain smallint in Postgres: only
        // the CHECK stops a double release from going below zero.
        $departure = Departure::factory()->create();

        $this->expectException(QueryException::class);
        $departure->decrement('booked_spots', 1);
    }

    public function test_the_database_refuses_a_departure_without_capacity(): void
    {
        $this->expectException(QueryException::class);
        Departure::factory()->create(['capacity' => 0]);
    }

    public function test_a_tour_cannot_have_two_departures_on_the_same_day(): void
    {
        $tour = Tour::factory()->create();
        Departure::factory()->for($tour)->create(['starts_on' => '2027-03-06']);

        $this->expectException(QueryException::class);
        Departure::factory()->for($tour)->create(['starts_on' => '2027-03-06']);
    }

    public function test_deleting_a_tour_deletes_its_departures(): void
    {
        $departure = Departure::factory()->create();

        $departure->tour->delete();

        $this->assertModelMissing($departure);
    }

    // --- The data migration ----------------------------------------------------
    //
    // Postgres runs schema changes inside the test's transaction, so each test
    // can roll migrations back, build data in the old shape, run them forward
    // again, and everything is undone afterwards (as in ReviewTest, Exercise 8).

    private const MOVE_BOOKINGS = '2026_11_26_000200_move_bookings_to_departures';

    private const DROP_TOUR_COLUMNS = '2026_11_26_000300_drop_dates_and_capacity_from_tours';

    private function migration(string $name): Migration
    {
        return require database_path("migrations/{$name}.php");
    }

    /** Rolls the schema back to the old shape: dates, capacity and bookings on tours. */
    private function rollBackToToursWithDates(): void
    {
        $this->migration(self::DROP_TOUR_COLUMNS)->down();
        $this->migration(self::MOVE_BOOKINGS)->down();
    }

    private function migrateForward(): void
    {
        $this->migration(self::MOVE_BOOKINGS)->up();
        $this->migration(self::DROP_TOUR_COLUMNS)->up();
    }

    /** A tour row as the old schema stored it (the factory knows only the new shape). */
    private function oldTour(string $startsOn, string $endsOn, int $capacity, int $bookedSpots, string $status = 'published'): int
    {
        $model = Tour::factory()->make();

        return DB::table('tours')->insertGetId([
            'operator_id' => User::factory()->operator()->create()->id,
            'city_id' => $model->city_id,
            'category_id' => $model->category_id,
            'title' => $model->title,
            'description' => $model->description,
            'price_cents' => 1_000,
            'capacity' => $capacity,
            'booked_spots' => $bookedSpots,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A booking row as the old schema stored it: on the tour, no departure. */
    private function oldBooking(int $tourId, int $userId, int $spots, string $status): int
    {
        return DB::table('bookings')->insertGetId([
            'reference' => 'LT-'.Str::upper(Str::random(8)),
            'user_id' => $userId,
            'tour_id' => $tourId,
            'spots' => $spots,
            'unit_price_cents' => 1_000,
            'subtotal_cents' => 1_000 * $spots,
            'discount_cents' => 0,
            'total_cents' => 1_000 * $spots,
            'payment_method' => 'wallet',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_migration_creates_one_departure_per_tour_and_links_every_booking(): void
    {
        $this->rollBackToToursWithDates();
        $traveler = User::factory()->create();
        $booked = $this->oldTour('2027-02-06', '2027-02-07', capacity: 12, bookedSpots: 3);
        $empty = $this->oldTour('2027-03-01', '2027-03-01', capacity: 8, bookedSpots: 0, status: 'draft');
        $confirmed = $this->oldBooking($booked, $traveler->id, 3, 'confirmed');
        $cancelled = $this->oldBooking($booked, $traveler->id, 2, 'cancelled');

        $this->migrateForward();

        $departures = DB::table('departures')->orderBy('tour_id')->get(['id', 'tour_id', 'starts_on', 'capacity', 'booked_spots']);
        $this->assertSame([
            [$booked, '2027-02-06', 12, 3],
            [$empty, '2027-03-01', 8, 0],
        ], $departures->map(fn ($row) => [$row->tour_id, $row->starts_on, $row->capacity, $row->booked_spots])->all());

        // Every booking, whatever its status, now points to its tour's departure.
        $this->assertSame(
            [$confirmed => $departures[0]->id, $cancelled => $departures[0]->id],
            DB::table('bookings')->orderBy('id')->pluck('departure_id', 'id')->all(),
        );

        // The tours keep their length and lose the moved columns.
        $this->assertSame([$booked => 2, $empty => 1], DB::table('tours')->orderBy('id')->pluck('duration_days', 'id')->all());
        foreach (['capacity', 'booked_spots', 'starts_on', 'ends_on'] as $column) {
            $this->assertFalse(Schema::hasColumn('tours', $column), "tours.{$column} still exists.");
        }

        // duration_days is no longer computed: it can be written directly.
        DB::table('tours')->where('id', $empty)->update(['duration_days' => 4]);
        $this->assertSame(4, DB::table('tours')->where('id', $empty)->value('duration_days'));

        // And departure_id is now required.
        $this->expectException(QueryException::class);
        $this->oldBooking($booked, $traveler->id, 1, 'confirmed');
    }

    public function test_rolling_back_gives_dates_capacity_and_counters_back_to_the_tours(): void
    {
        // A booking made *after* the migration only changed the departure's
        // counter, so down() must copy it back or tours.booked_spots would lie.
        $departure = Departure::factory()->for(Tour::factory()->state(['duration_days' => 3]))
            ->create(['capacity' => 9, 'starts_on' => '2027-04-03']);
        $this->actingAs($this->traveler(balanceCents: 1_000_000))
            ->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 2, 'payment_method' => 'wallet'])
            ->assertCreated();

        $this->rollBackToToursWithDates();

        $this->assertFalse(Schema::hasColumn('bookings', 'departure_id'));
        $this->assertSame(0, DB::table('departures')->count());
        $tour = DB::table('tours')->where('id', $departure->tour_id)->first();
        $this->assertSame(
            [9, 2, '2027-04-03', '2027-04-05', 3],
            [$tour->capacity, $tour->booked_spots, $tour->starts_on, $tour->ends_on, $tour->duration_days],
        );

        // The old constraints are back too.
        $this->expectException(QueryException::class);
        DB::table('tours')->where('id', $departure->tour_id)->update(['booked_spots' => 10]);
    }

    public function test_rolling_back_is_refused_while_a_tour_has_several_departures(): void
    {
        // The old shape has room for one date per tour. Keeping one and
        // dropping the others would silently lose bookings' dates.
        $tour = Tour::factory()->create();
        Departure::factory()->count(2)->for($tour)->create();

        $this->assertRollbackRefused((string) $tour->id);
        $this->assertSame(2, DB::table('departures')->count());
    }

    public function test_rolling_back_is_refused_while_a_tour_has_no_departure(): void
    {
        // The old columns are NOT NULL: there is no date to give such a tour.
        $tour = Tour::factory()->create();

        $this->assertRollbackRefused((string) $tour->id);
    }

    private function assertRollbackRefused(string $tourId): void
    {
        try {
            $this->rollBackToToursWithDates();
            $this->fail('The rollback was not refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("tour(s) {$tourId} do not have exactly one departure", $e->getMessage());
        }

        // Nothing was changed.
        $this->assertFalse(Schema::hasColumn('tours', 'starts_on'));
        $this->assertTrue(Schema::hasColumn('bookings', 'departure_id'));
    }
}
