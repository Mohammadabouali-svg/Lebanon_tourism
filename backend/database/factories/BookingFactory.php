<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/*
| Creates booking rows directly, for tests that need "a traveler who booked"
| as a starting point. It does NOT update the departure's booked_spots
| counter; tests of the booking rules themselves go through BookingService.
|
| Both ->for($tour) and ->for($departure) work, and tour_id always matches
| the departure (the composite foreign key requires it, D23):
|   - for($departure): tour_id is read from the departure;
|   - for($tour) or nothing: the tour's first departure is used, or one is
|     created (afterMaking, because departure_id needs tour_id first).
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
            // Listed before tour_id: closures see the attributes resolved before them.
            'departure_id' => null,
            'tour_id' => fn (array $attributes) => $attributes['departure_id'] !== null
                ? Departure::query()->whereKey($attributes['departure_id'])->value('tour_id')
                : Tour::factory(),
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

    public function configure(): static
    {
        return $this->afterMaking(function (Booking $booking) {
            $booking->departure_id ??= Departure::query()->where('tour_id', $booking->tour_id)->orderBy('id')->value('id')
                ?? Departure::factory()->create(['tour_id' => $booking->tour_id])->id;
        });
    }
}
