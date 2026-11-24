<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/*
| Creates booking rows directly, for tests that need "a traveler who booked"
| as a starting point. It does NOT update the tour's booked_spots counter;
| tests of the booking rules themselves go through BookingService.
|
| @extends Factory<Booking>
*/
class BookingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => 'LT-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'tour_id' => Tour::factory(),
            'spots' => 1,
            'unit_price_cents' => fn (array $attributes) => Tour::find($attributes['tour_id'])->price_cents,
            'subtotal_cents' => fn (array $attributes) => $attributes['unit_price_cents'] * $attributes['spots'],
            'discount_cents' => 0,
            'total_cents' => fn (array $attributes) => $attributes['subtotal_cents'] - $attributes['discount_cents'],
            'payment_method' => PaymentMethod::Wallet,
            'status' => BookingStatus::Confirmed,
            'paid_at' => now(),
        ];
    }
}
