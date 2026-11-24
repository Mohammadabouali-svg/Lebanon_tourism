<?php

namespace App\Policies;

use App\Models\Tour;
use App\Models\User;

/*
| A policy answers "may THIS user do THAT to THIS record?".
|
| Middleware answers the coarse question ("is this an approved operator?").
| The policy answers the fine one ("is this *their* tour?"). The original
| project had neither: any logged-in user could delete any package.
|
| Laravel finds this class automatically by name (Tour → TourPolicy).
| Controllers call it with Gate::authorize('update', $tour), which throws a
| 403 response when the method returns false.
*/
class TourPolicy
{
    public function update(User $user, Tour $tour): bool
    {
        return $user->isApprovedOperator() && $tour->operator_id === $user->id;
    }

    public function delete(User $user, Tour $tour): bool
    {
        return $this->update($user, $tour);
    }

    /** Admins may publish or archive any tour (content moderation). */
    public function moderate(User $user, Tour $tour): bool
    {
        return $user->isAdmin();
    }
}
