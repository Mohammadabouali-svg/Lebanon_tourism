<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Extra data that only tour operators have. Keeping it in its own table
| (instead of nullable columns on `users`) means travelers and admins do not
| carry empty company fields around.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 150)->unique();
            $table->string('business_phone', 30);
            // pending → approved by an admin → (maybe) suspended later.
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE operator_profiles ADD CONSTRAINT operator_profiles_status_valid CHECK (status IN ('pending', 'approved', 'suspended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_profiles');
    }
};
