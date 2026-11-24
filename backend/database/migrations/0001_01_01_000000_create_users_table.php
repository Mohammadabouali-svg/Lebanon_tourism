<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // Stored lower-case (see User::email()), so the unique index also
            // catches "Ana@Mail.com" vs "ana@mail.com".
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // One column for the role keeps authorization checks simple.
            // Allowed values come from App\Enums\UserRole.
            $table->string('role', 20)->default('traveler')->index();
            $table->string('phone', 30)->nullable();
            // Accounts are suspended, never deleted: bookings and payments
            // must stay traceable for accounting.
            $table->timestamp('suspended_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // The database itself refuses unknown roles, so a typo such as
        // 'Admin' or 'operater' can never sneak in.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('traveler', 'operator', 'admin'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            // Laravel stores a *hash* of the token, never the token itself.
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
