<?php

namespace App\Http\Controllers\Api\Operator;

use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TourResource;
use App\Queries\BookingStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);

        $operator = $request->user();
        $year = $request->integer('year', now()->year);
        $stats = BookingStats::forOperator($operator->id);

        // One grouped query instead of three separate COUNT queries.
        $toursByStatus = $operator->tours()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $upcoming = $operator->tours()
            ->where('status', TourStatus::Published)
            ->upcoming()
            ->with('city')
            ->orderBy('starts_on')
            ->limit(5)
            ->get();

        return response()->json(['data' => [
            'tours' => [
                'draft' => (int) ($toursByStatus['draft'] ?? 0),
                'published' => (int) ($toursByStatus['published'] ?? 0),
                'archived' => (int) ($toursByStatus['archived'] ?? 0),
            ],
            'totals' => $stats->totals(),
            'year' => $year,
            'years' => $stats->years(),
            'monthly' => $stats->monthly($year),
            'upcoming_departures' => TourResource::collection($upcoming),
        ]]);
    }
}
