<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Step 3 of 3 (contract, Exercise 10, docs/decisions.md D23): the dates,
| capacity and counter now live on departures, so the old copies on tours
| are dropped. Keeping them would leave two sources of truth that drift
| apart (tours.booked_spots stopped changing in 2026_11_26_000200).
|
| duration_days was a generated column (ends_on - starts_on + 1). DROP
| EXPRESSION turns it into a normal column and keeps every value, so the
| operator now enters the length directly. It is the length of every
| departure of the tour: their end dates are computed from it.
|
| The CHECK (1..30 days) is integrity, not a tunable business number: an end
| date is computed from it, and 0 or a negative length would put the end
| before the start (tours_dates_ordered guarded that before). 30 matches the
| itinerary's limit of 30 items. Every existing tour has a positive length
| (tours_dates_ordered); a tour longer than 30 days would make this
| migration fail loudly instead of being cut short.
|
| In a zero-downtime deploy this would ship one release later than the
| other two, once no running code reads these columns. Here code and
| migrations deploy together (D23).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tours ALTER COLUMN duration_days DROP EXPRESSION');
        DB::statement('ALTER TABLE tours ALTER COLUMN duration_days SET NOT NULL');
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_duration_range CHECK (duration_days BETWEEN 1 AND 30)');

        // Dropping a column also drops its CHECKs (tours_capacity_positive,
        // tours_no_overbooking, tours_dates_ordered) and the
        // (status, starts_on) index.
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'booked_spots', 'starts_on', 'ends_on']);
        });
    }

    /*
    | Puts the old columns back, filled from each tour's departure, and
    | restores the generated duration_days with its constraints and index.
    |
    | Only exact when every tour has exactly one departure (as right after
    | up()). A tour with several departures cannot be represented, and one
    | without a departure has no date to give the NOT NULL columns, so down()
    | refuses rather than invent or drop data.
    */
    public function down(): void
    {
        $notExactlyOne = DB::table('tours')
            ->leftJoin('departures', 'departures.tour_id', '=', 'tours.id')
            ->groupBy('tours.id')
            ->havingRaw('COUNT(departures.id) <> 1')
            ->orderBy('tours.id')
            ->pluck('tours.id');

        if ($notExactlyOne->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot roll back: tour(s) '.$notExactlyOne->implode(', ').' do not have exactly one departure, '
                .'and the old schema stores exactly one date per tour.'
            );
        }

        Schema::table('tours', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedSmallInteger('booked_spots')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE tours
            SET starts_on = departures.starts_on,
                ends_on = departures.starts_on + (tours.duration_days - 1),
                capacity = departures.capacity,
                booked_spots = departures.booked_spots
            FROM departures
            WHERE departures.tour_id = tours.id
            SQL);

        DB::statement('ALTER TABLE tours DROP CONSTRAINT tours_duration_range');
        DB::statement('ALTER TABLE tours DROP COLUMN duration_days');
        DB::statement('ALTER TABLE tours ALTER COLUMN capacity SET NOT NULL');
        DB::statement('ALTER TABLE tours ALTER COLUMN starts_on SET NOT NULL');
        DB::statement('ALTER TABLE tours ALTER COLUMN ends_on SET NOT NULL');
        DB::statement('ALTER TABLE tours ADD COLUMN duration_days integer GENERATED ALWAYS AS (ends_on - starts_on + 1) STORED');

        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_no_overbooking CHECK (booked_spots <= capacity)');
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_dates_ordered CHECK (ends_on >= starts_on)');

        Schema::table('tours', function (Blueprint $table) {
            $table->index(['status', 'starts_on']);
        });
    }
};
