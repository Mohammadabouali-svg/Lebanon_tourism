<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lebanon is divided into governorates (regions), which contain cities.
| A city belongs to exactly one region, so the relationship lives on `cities`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete: a region that still has cities cannot be deleted.
            // The database refuses, even if a bug in our code tries.
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            // Two regions may have a city with the same name, one region may not.
            $table->unique(['region_id', 'name']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('regions');
    }
};
