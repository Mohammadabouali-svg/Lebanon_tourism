<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Review photos: up to three per review (Exercise 8, docs/decisions.md D21).
|
| A child table instead of photo_1/photo_2/photo_3 columns: one row per
| photo, so "how many photos" and "in which order" are plain data.
|
| "At most three" is enforced by the database without a trigger:
|   - `position` must be 1, 2 or 3 (CHECK review_photos_position_range);
|   - a review cannot use the same position twice (unique index).
| Three distinct values out of three possible: a fourth row cannot exist,
| even if two requests raced or a future code path forgot the validation.
|
| ON DELETE CASCADE: photos are owned by their review and mean nothing
| without it. The files on disk are not touched by the database; the code
| that deletes a review (ReviewService::delete) removes them after commit.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('path');
            $table->timestamps();

            // Also serves "the photos of these reviews, in order" (eager loading).
            $table->unique(['review_id', 'position']);
        });

        DB::statement('ALTER TABLE review_photos ADD CONSTRAINT review_photos_position_range CHECK (position BETWEEN 1 AND 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
    }
};
