<?php

namespace App\Policies;

use App\Models\Departure;
use App\Models\User;

/*
| A departure belongs to whoever owns its tour: the rule is TourPolicy's,
| asked about the departure's tour, so the two can never disagree.
*/
class DeparturePolicy
{
    public function __construct(private readonly TourPolicy $tours) {}

    public function update(User $user, Departure $departure): bool
    {
        // loadMissing: strict mode forbids lazy loading, and the controller's
        // response needs the tour anyway.
        return $this->tours->update($user, $departure->loadMissing('tour')->tour);
    }

    public function delete(User $user, Departure $departure): bool
    {
        return $this->update($user, $departure);
    }
}
