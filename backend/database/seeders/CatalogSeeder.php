<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Region;
use Illuminate\Database\Seeder;

/*
| Lebanon's governorates and a selection of cities that tours visit.
|
| firstOrCreate() makes the seeder idempotent: running it twice does not
| create duplicates, so it is safe to run on a database that already has data.
*/
class CatalogSeeder extends Seeder
{
    private const REGIONS = [
        'Beirut' => ['Beirut'],
        'Mount Lebanon' => ['Deir el Qamar', 'Beiteddine', 'Broummana', 'Barouk'],
        'Keserwan-Jbeil' => ['Byblos', 'Jounieh', 'Jeita', 'Faqra'],
        'North Lebanon' => ['Tripoli', 'Batroun', 'Bsharri', 'Ehden'],
        'Akkar' => ['Halba', 'Qoubaiyat'],
        'Baalbek-Hermel' => ['Baalbek', 'Hermel'],
        'Bekaa' => ['Zahle', 'Anjar', 'Chtaura'],
        'South Lebanon' => ['Sidon', 'Tyre'],
        'Nabatieh' => ['Nabatieh', 'Arnoun'],
    ];

    private const CATEGORIES = [
        'Cultural & Heritage', 'Adventure & Hiking', 'Food & Wine',
        'Nature', 'Beach & Coast', 'Religious Heritage',
    ];

    public function run(): void
    {
        foreach (self::REGIONS as $regionName => $cities) {
            $region = Region::firstOrCreate(['name' => $regionName]);

            foreach ($cities as $cityName) {
                $region->cities()->firstOrCreate(['name' => $cityName]);
            }
        }

        foreach (self::CATEGORIES as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}
