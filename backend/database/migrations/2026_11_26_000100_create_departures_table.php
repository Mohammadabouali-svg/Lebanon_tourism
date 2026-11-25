<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Departures: the dates a tour runs on (Exercise 10, docs/decisions.md D23).
|
| Step 1 of 3 (expand): the table is created next to the old tour columns
| and nothing reads it yet. 2026_11_26_000200 moves the data and the
| bookings here, 2026_11_26_000300 drops the old columns from tours.
|
| Only the start date is stored. The end date is starts_on +
| tours.duration_days - 1: the itinerary ("Day 3: ...") belongs to the tour,
| so every departure of a tour must last the same number of days.
|
| Capacity and the booked_spots counter move here: the spots of the
| 6 March departure have nothing to do with those of 13 March.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departures', function (Blueprint $table) {
            $table->id();
            // Cascade: a tour that can be deleted (TourService refuses when it
            // has bookings) takes its empty departures with it. Departures
            // that do have bookings are protected by the bookings FK, which
            // restricts deletion.
            $table->foreignId('tour_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->unsignedSmallInteger('capacity');
            // Kept in sync by BookingService inside a transaction, under a lock
            // on this row. The CHECKs below make overbooking impossible.
            $table->unsignedSmallInteger('booked_spots')->default(0);
            $table->timestamps();

            // One departure per tour per day. The index also serves the
            // catalogue's "next departure of this tour" lookups.
            $table->unique(['tour_id', 'starts_on'], 'departures_one_per_day');
            // Target of the composite foreign key bookings(departure_id,
            // tour_id): it lets Postgres guarantee that a booking's tour is
            // the tour of its departure (added in 2026_11_26_000200).
            $table->unique(['id', 'tour_id'], 'departures_id_tour_unique');
        });

        DB::statement('ALTER TABLE departures ADD CONSTRAINT departures_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE departures ADD CONSTRAINT departures_no_overbooking CHECK (booked_spots <= capacity)');
        // "unsigned" means nothing in Postgres (smallint is signed), so a
        // release of spots that runs twice would go below zero without this.
        DB::statement('ALTER TABLE departures ADD CONSTRAINT departures_booked_spots_not_negative CHECK (booked_spots >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('departures');
    }
};
