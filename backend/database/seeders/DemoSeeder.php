<?php

namespace Database\Seeders;

use App\Enums\OperatorStatus;
use App\Enums\PaymentMethod;
use App\Enums\TourStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Discount;
use App\Models\Review;
use App\Models\Tour;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/*
| Demo data for local development. Every account's password is "password".
| The accounts are listed in README.md.
|
| Wallet credit and bookings are created through the real services
| (WalletService, BookingService) rather than inserted directly. The demo
| data therefore obeys the same rules as real data: ledger entries exist,
| spot counters match the bookings, discounts are counted.
*/
class DemoSeeder extends Seeder
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly BookingService $bookings,
    ) {}

    public function run(): void
    {
        if (User::where('email', 'admin@lebanon-tourism.test')->exists()) {
            $this->command->warn('Demo data already present, skipping. Use `make fresh` to start over.');

            return;
        }

        $admin = User::factory()->admin()->create(['name' => 'Nadine Admin', 'email' => 'admin@lebanon-tourism.test']);

        $cedars = User::factory()->operator()->create(['name' => 'Elie Rahme', 'email' => 'operator@lebanon-tourism.test']);
        $cedars->operatorProfile->update(['company_name' => 'Cedars Trails']);

        $phoenician = User::factory()->operator()->create(['name' => 'Rana Saad', 'email' => 'operator2@lebanon-tourism.test']);
        $phoenician->operatorProfile->update(['company_name' => 'Phoenician Journeys']);

        $pending = User::factory()->operator(OperatorStatus::Pending)->create(['name' => 'Fadi Nassar', 'email' => 'pending@lebanon-tourism.test']);
        $pending->operatorProfile->update(['company_name' => 'Bekaa Valley Escapes']);

        $maya = User::factory()->create(['name' => 'Maya Khoury', 'email' => 'traveler@lebanon-tourism.test']);
        $karim = User::factory()->create(['name' => 'Karim Haddad', 'email' => 'traveler2@lebanon-tourism.test']);
        User::factory()->unverified()->create(['name' => 'Lina Unverified', 'email' => 'unverified@lebanon-tourism.test']);

        $this->wallets->credit($maya->wallet, 150000, 'Welcome credit (demo)', createdBy: $admin);
        $this->wallets->credit($karim->wallet, 30000, 'Welcome credit (demo)', createdBy: $admin);

        $tours = $this->createTours($cedars, $phoenician);

        Discount::factory()->percent(10)->create(['code' => 'WELCOME10', 'description' => '10% off your first trip']);
        Discount::factory()->fixed(2500)->create([
            'code' => 'SUMMER25',
            'description' => '$25 off summer tours',
            'max_redemptions' => 100,
            'expires_at' => now()->addMonths(2)->endOfMonth(),
        ]);
        Discount::factory()->percent(5)->create(['code' => 'EXPIRED5', 'expires_at' => now()->subDay()]);

        // Bookings through the real service (so spots, wallet and ledger agree).
        $this->bookings->book($maya, $tours['byblos'], 2, PaymentMethod::Wallet, 'WELCOME10');
        $this->bookings->book($maya, $tours['qadisha'], 1, PaymentMethod::Wallet, null);
        $this->bookings->book($karim, $tours['byblos'], 1, PaymentMethod::Card, null);
        $this->bookings->book($karim, $tours['tripoli'], 3, PaymentMethod::Card, 'SUMMER25');

        $this->review($maya, $tours['byblos'], 5, 'Our guide brought every layer of the old city to life, from the Crusader castle to the Phoenician temples. Unforgettable.');
        $this->review($karim, $tours['byblos'], 4, 'Great day out. The fish lunch by the old harbour was a highlight. A bit rushed at the end.');
        $this->review($karim, $tours['tripoli'], 5, 'The best food tour I have ever done. Come hungry!');
    }

    /** @return array<string, Tour> */
    private function createTours(User $cedars, User $phoenician): array
    {
        $city = fn (string $name) => City::where('name', $name)->firstOrFail();
        $category = fn (string $name) => Category::where('name', $name)->firstOrFail();
        $in = fn (int $days) => CarbonImmutable::today()->addDays($days);

        $definitions = [
            'byblos' => [$phoenician, 'Byblos', 'Cultural & Heritage', 'Byblos: 7,000 Years in a Day', 6500, 20, $in(12), 1, [
                [1, 'Crusader castle and Phoenician temples', 'Guided walk through one of the oldest continuously inhabited cities in the world.'],
                [1, 'Lunch at the old harbour', 'Fresh fish and mezze facing the Mediterranean.'],
                [1, 'Old souk', 'Free time among artisans and fossil shops.'],
            ]],
            'jeita' => [$phoenician, 'Jeita', 'Nature', 'Jeita Grotto & Harissa Panorama', 5500, 25, $in(18), 1, [
                [1, 'Jeita Grotto', 'Boat ride through the lower cave and walk through the upper galleries.'],
                [1, 'Téléphérique to Harissa', 'Cable car ride with a view over the Bay of Jounieh.'],
            ]],
            'baalbek' => [$phoenician, 'Baalbek', 'Cultural & Heritage', 'Baalbek Temples & Ksara Wine Cellars', 9500, 18, $in(25), 2, [
                [1, 'Temples of Baalbek', 'The temples of Jupiter and Bacchus, among the best-preserved Roman ruins anywhere.'],
                [2, 'Ksara caves and Zahle', 'Tasting in the Roman-era tunnels of Château Ksara, lunch by the Berdawni river.'],
            ]],
            'qadisha' => [$cedars, 'Bsharri', 'Adventure & Hiking', 'Qadisha Valley Monasteries Trek', 14000, 12, $in(30), 3, [
                [1, 'Descent into the Holy Valley', 'Trail from Bsharri down to Qannoubine monastery.'],
                [2, 'Rock-cut hermitages', 'Visit the cave chapels of Mar Antonios Qozhaya.'],
                [3, 'Cedars of God', 'Climb out of the valley to the ancient cedar forest.'],
            ]],
            'cedars' => [$cedars, 'Bsharri', 'Adventure & Hiking', 'Cedars Snowshoe Weekend', 21000, 10, $in(60), 2, [
                [1, 'Snowshoe trail through the cedars', 'Beginner-friendly route with mountain guides.'],
                [2, 'Summit sunrise', 'Optional early hike towards Qornet es Sawda.'],
            ]],
            'bekaa' => [$cedars, 'Zahle', 'Food & Wine', 'Bekaa Wine Route', 12000, 16, $in(40), 2, [
                [1, 'Three family wineries', 'Tastings and vineyard walks in the West Bekaa.'],
                [2, 'Anjar Umayyad city', 'Morning visit before the return to Beirut.'],
            ]],
            'tyre' => [$phoenician, 'Tyre', 'Beach & Coast', 'Tyre & Sidon Coastal Heritage', 8500, 20, $in(35), 2, [
                [1, 'Sidon sea castle and soap museum', 'History of the Phoenician port city.'],
                [2, 'Al-Mina and the hippodrome of Tyre', 'Roman ruins, then an afternoon on the beach.'],
            ]],
            'shouf' => [$cedars, 'Barouk', 'Adventure & Hiking', 'Lebanon Mountain Trail: Shouf Section', 39000, 12, $in(50), 5, [
                [1, 'Barouk cedar reserve', 'Start of the trek in the Shouf Biosphere Reserve.'],
                [3, 'Deir el Qamar', 'Night in a guesthouse in the old village.'],
                [5, 'Beiteddine palace', 'Finish at the 19th-century palace.'],
            ]],
            'tripoli' => [$phoenician, 'Tripoli', 'Food & Wine', 'Tripoli Souks & Street Food', 4500, 15, $in(9), 1, [
                [1, 'Citadel of Raymond de Saint-Gilles', 'Overview of the city from the Crusader fortress.'],
                [1, 'Tasting walk', 'Kaak, foul, knefeh and the famous sweets of Tripoli.'],
            ]],
            'batroun' => [$cedars, 'Batroun', 'Beach & Coast', 'Batroun Beach & Sunset Sail', 7500, 14, $in(20), 1, [
                [1, 'Phoenician wall and old town', 'Morning stroll and fresh lemonade.'],
                [1, 'Sunset sail', 'Two hours on the water before dinner by the sea.'],
            ]],
        ];

        // Some tours have no meeting point (hotel pickup), to show it is optional.
        $meetingPoints = [
            'jeita' => 'Jeita Grotto ticket office, 9:00 AM',
            'baalbek' => 'National Museum of Beirut, main gate, 7:30 AM',
            'qadisha' => 'Saint Saba church square, Bsharri, 8:00 AM',
            'tripoli' => 'Clock tower, Al-Tall square, Tripoli, 10:00 AM',
            'batroun' => 'Batroun old harbour, 4:00 PM',
        ];

        $tours = [];
        foreach ($definitions as $key => [$operator, $cityName, $categoryName, $title, $price, $capacity, $start, $days, $itinerary]) {
            $tour = Tour::factory()->for($operator, 'operator')->create([
                'city_id' => $city($cityName)->id,
                'category_id' => $category($categoryName)->id,
                'title' => $title,
                'description' => "{$title}. Small groups, local guides and transport from Beirut included. "
                    .'Bring comfortable shoes, a water bottle and a sense of curiosity.',
                'meeting_point' => $meetingPoints[$key] ?? null,
                'price_cents' => $price,
                'capacity' => $capacity,
                'starts_on' => $start->toDateString(),
                'ends_on' => $start->addDays($days - 1)->toDateString(),
                'status' => TourStatus::Published,
            ]);

            $tour->itineraryItems()->createMany(array_map(
                fn (array $item) => ['day' => $item[0], 'title' => $item[1], 'description' => $item[2]],
                $itinerary,
            ));

            $tours[$key] = $tour;
        }

        // One draft (invisible to the public) to show the operator workflow.
        Tour::factory()->draft()->for($cedars, 'operator')->create([
            'city_id' => $city('Ehden')->id,
            'category_id' => $category('Nature')->id,
            'title' => 'Horsh Ehden Nature Reserve (draft)',
        ]);

        return $tours;
    }

    private function review(User $author, Tour $tour, int $rating, string $comment): void
    {
        $review = new Review(['rating' => $rating, 'comment' => $comment]);
        $review->user()->associate($author);
        $review->tour()->associate($tour);
        $review->save();
    }
}
