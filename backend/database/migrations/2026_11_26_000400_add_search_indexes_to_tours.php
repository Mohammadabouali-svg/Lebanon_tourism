<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Indexes for the public search (Exercise 11, docs/decisions.md D24).
|
| The search is `title ILIKE '%term%' OR city_id IN (cities whose name
| matches)`. Measured on 100,000 tours, both sides were sequential scans.
|
| tours_title_trgm: a btree index cannot answer a pattern with a leading
| wildcard. pg_trgm splits text into three-letter pieces ("hik", "ike", ...)
| and a GIN index over them finds the rows containing every piece of the
| term; Postgres then re-checks the real ILIKE on those rows. The semantics
| stay exactly "contains, ignoring case": no stemming, no whole-word rule.
| Terms shorter than 3 characters have no trigram, and the planner keeps
| the sequential scan for them.
| GIN rather than GiST: faster to read, slower to write, and the catalogue
| is read far more often than tours are saved.
|
| tours_city_id_index: Postgres does not index foreign keys by itself. An
| OR can only be answered from indexes (a BitmapOr) when *both* sides have
| one; without this index the title index is never used for the search.
|
| pg_trgm ships with Postgres and is a "trusted" extension (PG 13+), so the
| database owner can create it without superuser rights.
|
| Not CONCURRENTLY: that cannot run inside a transaction, and Laravel runs
| each Postgres migration in one, which keeps it all-or-nothing. On a table
| of this size the write lock lasts milliseconds. For a large, busy table,
| CREATE INDEX CONCURRENTLY with `$withinTransaction = false` would avoid
| blocking writes while the index is built.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX tours_title_trgm ON tours USING gin (title gin_trgm_ops)');

        Schema::table('tours', function (Blueprint $table) {
            $table->index('city_id'); // tours_city_id_index
        });
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropIndex(['city_id']);
        });

        DB::statement('DROP INDEX tours_title_trgm');
        // No CASCADE: if anything else ever depends on pg_trgm, this fails
        // loudly instead of silently dropping that other object too.
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
