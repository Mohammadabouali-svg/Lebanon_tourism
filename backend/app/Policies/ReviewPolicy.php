<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Review;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReviewPolicy
{
    /*
    | Only travelers who actually booked the tour may review it, once.
    |
    | Returning a Response (instead of true/false) lets us explain *why* the
    | action is denied. The message ends up in the 403 JSON body.
    */
    public function create(User $user, Tour $tour): Response
    {
        if (! $user->isTraveler()) {
            return Response::deny('Only travelers can review tours.');
        }

        $hasBooked = $tour->bookings()
            ->whereBelongsTo($user)
            ->where('status', BookingStatus::Confirmed)
            ->exists();

        if (! $hasBooked) {
            return Response::deny('You can only review tours you have booked.');
        }

        if ($tour->reviews()->whereBelongsTo($user)->exists()) {
            return Response::deny('You have already reviewed this tour.');
        }

        return Response::allow();
    }

    public function delete(User $user, Review $review): bool
    {
        return $review->user_id === $user->id || $user->isAdmin();
    }
}
