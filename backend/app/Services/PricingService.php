<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Tour;
use App\Models\User;

/*
| Computes what a booking costs. The price ALWAYS comes from the database,
| never from the request: whatever the browser shows is just a preview, and
| the browser is fully under the user's control.
|
| The same class serves two callers:
|   - the "quote" endpoint, so the checkout page can show an exact total
|   - BookingService, when the booking is actually made
| Sharing one implementation guarantees the preview and the charge agree.
*/
final class PricingService
{
    /**
     * @param  bool  $lockDiscount  lock the discount row (only inside a booking transaction)
     *
     * @throws BusinessRuleException when the discount code cannot be used
     */
    public function quote(Tour $tour, int $spots, ?string $discountCode, User $user, bool $lockDiscount = false): Quote
    {
        $subtotal = $tour->price_cents * $spots;
        $discount = null;
        $discountCents = 0;

        if ($discountCode !== null && trim($discountCode) !== '') {
            $discount = $this->findUsableDiscount($discountCode, $user, $lockDiscount);
            $discountCents = $discount->amountOffCents($subtotal);
        }

        return new Quote(
            unitPriceCents: $tour->price_cents,
            spots: $spots,
            subtotalCents: $subtotal,
            discountCents: $discountCents,
            totalCents: $subtotal - $discountCents,
            discount: $discount,
        );
    }

    private function findUsableDiscount(string $code, User $user, bool $lock): Discount
    {
        $discount = Discount::query()
            ->where('code', mb_strtoupper(trim($code)))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        // Same message for "does not exist" and "exists but inactive" would
        // also be fine; here we only hide whether a code ever existed.
        if ($discount === null) {
            throw BusinessRuleException::invalidDiscount('This discount code does not exist.');
        }

        if ($reason = $discount->unavailableReason(now())) {
            throw BusinessRuleException::invalidDiscount($reason);
        }

        $alreadyUsed = Booking::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($discount)
            ->whereIn('status', BookingStatus::active())
            ->exists();

        if ($alreadyUsed) {
            throw BusinessRuleException::invalidDiscount('You have already used this discount code.');
        }

        return $discount;
    }
}
