<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description');

            // Money is an integer number of cents. Never use float/double for
            // money: 0.1 + 0.2 is 0.30000000000000004 in floating point.
            $table->unsignedInteger('price_cents');

            // `booked_spots` is a counter kept in sync by BookingService inside
            // a transaction. The CHECK constraint below makes overbooking
            // impossible at the database level, whatever the PHP code does.
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('booked_spots')->default(0);

            $table->date('starts_on');
            $table->date('ends_on');
            // A generated column: Postgres computes it from the two dates, so
            // the duration can never disagree with them. (Subtracting two
            // dates in Postgres gives a number of days.)
            $table->integer('duration_days')->storedAs('ends_on - starts_on + 1');

            $table->string('status', 20)->default('draft');
            $table->string('image_path')->nullable();
            $table->timestamps();

            // The public listing filters on status and date on every request.
            $table->index(['status', 'starts_on']);
        });

        // Laravel's schema builder has no CHECK constraint helper, so we use
        // plain SQL. These rules hold even for rows written outside Laravel.
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_price_positive CHECK (price_cents > 0)');
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_no_overbooking CHECK (booked_spots <= capacity)');
        DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_dates_ordered CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE tours ADD CONSTRAINT tours_status_valid CHECK (status IN ('draft', 'published', 'archived'))");

        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['tour_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
        Schema::dropIfExists('tours');
    }
};
