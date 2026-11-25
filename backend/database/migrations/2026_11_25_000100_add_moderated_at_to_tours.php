<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The admin moderation lock (Exercise 6, docs/decisions.md D19).
|
| `moderated_at` is set when an admin archives a tour and cleared when an
| admin publishes it (or sets it back to draft). While it is set, the
| operator cannot change the tour's status: TourService refuses with the
| `tour_moderated` code.
|
| Nullable, so existing tours get NULL ("not locked") and need no backfill.
| A timestamp instead of a boolean: it also records *when* the admin acted,
| which the operator's form and the admin list show.
|
| The CHECK states what the lock means: a locked tour is an archived tour.
| It is integrity, not a tunable business number (compare D17): a tour that
| is locked *and* published would be contradictory data. It also makes the
| exercise's rule hold for code that bypasses TourService (tinker, imports).
| Every existing row has moderated_at NULL, so adding it cannot fail.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->timestampTz('moderated_at')->nullable();
        });

        DB::statement("ALTER TABLE tours ADD CONSTRAINT tours_moderated_means_archived CHECK (moderated_at IS NULL OR status = 'archived')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tours DROP CONSTRAINT tours_moderated_means_archived');

        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn('moderated_at');
        });
    }
};
