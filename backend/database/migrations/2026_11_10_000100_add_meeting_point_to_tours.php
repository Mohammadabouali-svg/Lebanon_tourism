<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Where travelers meet the guide on day one, e.g. "Main gate of the National
| Museum, Beirut, 7:30 AM". Optional: many tours include a hotel pickup.
|
| A new migration instead of editing create_tours_table: that one has
| already run on every existing database, so changing it would do nothing
| there (see docs/04-database.md, "Changing the schema").
|
| Nullable, so existing tours simply get NULL ("no meeting point given")
| and no backfill is needed. 255 characters is a place plus a time, not an
| essay; TourRequest enforces the same limit with a readable message.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            // No ->after(): Postgres always appends new columns at the end.
            $table->string('meeting_point', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn('meeting_point');
        });
    }
};
