<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OperatorStatus;
use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Models\OperatorProfile;
use App\Models\Tour;
use App\Models\User;
use App\Queries\BookingStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);

        $year = $request->integer('year', now()->year);
        $stats = BookingStats::global();

        $usersByRole = User::query()->toBase()
            ->selectRaw('role, COUNT(*) AS total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return response()->json(['data' => [
            'users' => [
                'travelers' => (int) ($usersByRole['traveler'] ?? 0),
                'operators' => (int) ($usersByRole['operator'] ?? 0),
                'admins' => (int) ($usersByRole['admin'] ?? 0),
            ],
            'pending_operators' => OperatorProfile::query()->where('status', OperatorStatus::Pending)->count(),
            'published_tours' => Tour::query()->where('status', TourStatus::Published)->count(),
            'totals' => $stats->totals(),
            'year' => $year,
            'years' => $stats->years(),
            'monthly' => $stats->monthly($year),
        ]]);
    }
}
