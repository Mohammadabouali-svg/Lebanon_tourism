<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Step 2 of 3 (migrate, Exercise 10, docs/decisions.md D23): every existing
| tour becomes one departure, and every booking is attached to it.
|
| Before this change a tour had exactly one date, so the mapping is one to
| one and nothing has to be guessed: the departure takes the tour's date,
| capacity and booked_spots counter, and each booking (whatever its status)
| gets the departure of its tour.
|
| Both copies are single INSERT ... SELECT / UPDATE ... FROM statements run
| inside Postgres: no rows pass through PHP, and the whole migration is one
| transaction (Postgres runs DDL transactionally), so it applies completely
| or not at all.
|
| bookings.tour_id stays (denormalised). The composite foreign key
| (departure_id, tour_id) → departures(id, tour_id) lets the database
| guarantee it always names the departure's tour. Keeping it means the
| review rule, operator scoping, exports and dashboard queries are unchanged.
|
| The column is added nullable, backfilled, then made NOT NULL: adding a NOT
| NULL column without a default fails on a table that already has rows.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO departures (tour_id, starts_on, capacity, booked_spots, created_at, updated_at)
            SELECT id, starts_on, capacity, booked_spots, NOW(), NOW()
            FROM tours
            SQL);

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('departure_id')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE bookings
            SET departure_id = departures.id
            FROM departures
            WHERE departures.tour_id = bookings.tour_id
            SQL);

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('departure_id')->nullable(false)->change();
            // Restrict: a departure that has bookings can never be deleted,
            // whatever the PHP code does.
            $table->foreign(['departure_id', 'tour_id'], 'bookings_departure_of_tour_foreign')
                ->references(['id', 'tour_id'])->on('departures')
                ->restrictOnDelete();
            // Counting and listing a departure's bookings by status.
            $table->index(['departure_id', 'status']);
        });
    }

    /*
    | Bookings made after up() changed only the departure counters, so the
    | tours get the departure's date, capacity and counter back before the
    | departures are deleted (the tour's length is kept: ends_on moves with
    | starts_on).
    |
    | The old shape holds one date per tour. If a tour has several
    | departures, rolling back would have to drop all but one of them and
    | their bookings' dates with them, so down() refuses instead. Tours
    | without a departure keep their old columns unchanged.
    */
    public function down(): void
    {
        $tooMany = DB::table('departures')->select('tour_id')->groupBy('tour_id')->havingRaw('COUNT(*) > 1')->pluck('tour_id');

        if ($tooMany->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot roll back: tour(s) '.$tooMany->implode(', ').' have more than one departure, '
                .'and the old schema can store only one date per tour.'
            );
        }

        DB::statement(<<<'SQL'
            UPDATE tours
            SET starts_on = departures.starts_on,
                ends_on = departures.starts_on + (tours.ends_on - tours.starts_on),
                capacity = departures.capacity,
                booked_spots = departures.booked_spots
            FROM departures
            WHERE departures.tour_id = tours.id
            SQL);

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign('bookings_departure_of_tour_foreign');
            $table->dropIndex(['departure_id', 'status']);
            $table->dropColumn('departure_id');
        });

        DB::table('departures')->delete();
    }
};
