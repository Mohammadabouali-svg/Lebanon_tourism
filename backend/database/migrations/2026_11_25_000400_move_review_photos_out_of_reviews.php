<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Moves the single review photo (reviews.photo_path) into review_photos
| (Exercise 8, docs/decisions.md D21), then drops the old column so there is
| one source of truth.
|
| The copy is one INSERT ... SELECT inside the database: no rows are loaded
| into PHP, and it is atomic together with the column drop (Postgres runs
| schema changes in the migration's transaction). The file on disk does not
| move; only the row pointing to it does.
|
| down() puts the photo in position 1 back into photo_path. Photos 2 and 3
| cannot be represented in the old shape: their rows are lost on rollback
| (their files stay on disk). That is accepted: a rollback is an emergency
| tool, and the old code could not show those photos anyway.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO review_photos (review_id, position, path, created_at, updated_at)
            SELECT id, 1, photo_path, created_at, updated_at
            FROM reviews
            WHERE photo_path IS NOT NULL
            SQL);

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE reviews
            SET photo_path = review_photos.path
            FROM review_photos
            WHERE review_photos.review_id = reviews.id AND review_photos.position = 1
            SQL);

        DB::table('review_photos')->delete();
    }
};
