<?php

namespace App\Http\Resources;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'spots' => $this->spots,
            'unit_price_cents' => $this->unit_price_cents,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'total_cents' => $this->total_cents,
            'discount_code' => $this->whenLoaded('discount', fn () => $this->discount?->code),
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            // Stripe's payment page (Exercise 9): only while there is still
            // something to pay, and only to the traveler who booked.
            // Operators and admins have no business on it.
            'checkout_url' => $this->status === BookingStatus::Pending && $request->user()?->id === $this->user_id
                ? $this->checkout_url
                : null,
            'can_cancel' => $this->whenLoaded('departure', fn () => $this->isCancellable()),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tour' => TourResource::make($this->whenLoaded('tour')),
            // The date booked (Exercise 10). Needs `departure.tour` loaded (see DepartureResource).
            'departure' => DepartureResource::make($this->whenLoaded('departure')),
            // Operators and admins see who booked; travelers only see their own bookings anyway.
            'traveler' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),
        ];
    }
}
