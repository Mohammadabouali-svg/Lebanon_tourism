<?php

namespace Tests\Feature\Booking;

use App\Models\Departure;
use App\Models\Discount;
use App\Models\Tour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_quote_endpoint_returns_the_server_side_price(): void
    {
        Discount::factory()->percent(10)->create(['code' => 'WELCOME10']);
        $departure = Departure::factory()->for(Tour::factory()->state(['price_cents' => 6_500]))->create();

        $this->actingAs($this->traveler())
            ->postJson("/api/departures/{$departure->id}/quote", ['spots' => 2, 'discount_code' => 'welcome10'])
            ->assertOk()
            ->assertJsonPath('data.subtotal_cents', 13_000)
            ->assertJsonPath('data.discount_cents', 1_300)
            ->assertJsonPath('data.total_cents', 11_700)
            ->assertJsonPath('data.discount_code', 'WELCOME10');
    }

    public function test_the_quote_shows_the_spots_left_on_that_departure(): void
    {
        $tour = Tour::factory()->create();
        Departure::factory()->for($tour)->create(['capacity' => 10]);
        $departure = Departure::factory()->for($tour)->create(['capacity' => 6, 'booked_spots' => 4]);

        $this->actingAs($this->traveler())
            ->postJson("/api/departures/{$departure->id}/quote", ['spots' => 1])
            ->assertOk()
            ->assertJsonPath('data.available_spots', 2);
    }

    public function test_departures_of_unpublished_tours_cannot_be_quoted(): void
    {
        // Draft tours of other operators must not leak their prices.
        $departure = Departure::factory()->for(Tour::factory()->draft())->create();

        $this->actingAs($this->traveler())
            ->postJson("/api/departures/{$departure->id}/quote", ['spots' => 1])
            ->assertNotFound();
    }

    public function test_a_fixed_discount_never_makes_the_total_negative(): void
    {
        Discount::factory()->fixed(50_000)->create(['code' => 'HUGE']);
        $traveler = $this->traveler();
        $departure = Departure::factory()->for(Tour::factory()->state(['price_cents' => 4_000]))->create();

        $this->actingAs($traveler)
            ->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet', 'discount_code' => 'HUGE'])
            ->assertCreated()
            ->assertJsonPath('data.discount_cents', 4_000)
            ->assertJsonPath('data.total_cents', 0);

        // Nothing to pay, so no ledger entry and no negative balance.
        $this->assertSame(0, $traveler->wallet->fresh()->balance_cents);
    }

    public function test_each_traveler_can_use_a_code_once(): void
    {
        Discount::factory()->percent(10)->create(['code' => 'ONCE']);
        $departure = Departure::factory()->create(['capacity' => 10]);
        $this->actingAs($this->traveler(balanceCents: 1_000_000));

        $this->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet', 'discount_code' => 'ONCE'])
            ->assertCreated();

        $this->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet', 'discount_code' => 'ONCE'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_discount');
    }

    public function test_a_cancelled_booking_gives_the_code_back(): void
    {
        $discount = Discount::factory()->percent(10)->create(['code' => 'AGAIN', 'max_redemptions' => 1]);
        $departure = Departure::factory()->create(['capacity' => 10]);
        $this->actingAs($this->traveler(balanceCents: 1_000_000));

        $bookingId = $this->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet', 'discount_code' => 'AGAIN'])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame(1, $discount->fresh()->redemptions_count);

        $this->postJson("/api/bookings/{$bookingId}/cancel")->assertOk();
        $this->assertSame(0, $discount->fresh()->redemptions_count);

        $this->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet', 'discount_code' => 'AGAIN'])
            ->assertCreated();
    }

    public function test_expired_inactive_and_exhausted_codes_are_refused(): void
    {
        Discount::factory()->create(['code' => 'OLD', 'expires_at' => now()->subMinute()]);
        Discount::factory()->create(['code' => 'OFF', 'is_active' => false]);
        Discount::factory()->create(['code' => 'GONE', 'max_redemptions' => 5])->forceFill(['redemptions_count' => 5])->save();
        $departure = Departure::factory()->create();
        $this->actingAs($this->traveler());

        foreach (['OLD', 'OFF', 'GONE', 'DOES-NOT-EXIST'] as $code) {
            $this->postJson("/api/departures/{$departure->id}/quote", ['spots' => 1, 'discount_code' => $code])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'invalid_discount');
        }
    }
}
