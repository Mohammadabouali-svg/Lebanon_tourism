<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E-mail address change (Exercise 4, docs/decisions.md D20).
|
| `pending_email` holds a requested new address until the link mailed to it
| is clicked; only then does EmailChangeService copy it into `email`.
| Nullable, so existing users get NULL ("no change pending"): no backfill.
|
| Deliberately NO unique index on pending_email. With one, anyone could put
| someone else's address in their own pending_email and stop the real owner
| from ever requesting it. Uniqueness is enforced where it matters: on
| `email`, at the moment of the swap (service check + the existing unique
| index as the last line of defence).
|
| The CHECK states a fact that must always hold: a pending address is a
| *different* address. Both columns are stored lower-case (model mutators),
| so a plain comparison is enough. Every existing row has NULL, so adding
| it cannot fail.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_pending_email_differs CHECK (pending_email IS NULL OR pending_email <> email)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_pending_email_differs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });
    }
};
