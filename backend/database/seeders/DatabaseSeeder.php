<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/*
| `php artisan db:seed` runs this class.
|
|   CatalogSeeder - regions, cities and categories. Real reference data,
|                   useful in every environment, including production.
|   DemoSeeder    - fake accounts, tours and bookings with well-known
|                   passwords. Refuses to run in production.
*/
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogSeeder::class);

        if (app()->isProduction()) {
            $this->command->warn('Production environment: demo data skipped.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
