<?php

namespace App\Http\Controllers\Api\Operator;

use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepartureResource;
use App\Http\Resources\TourResource;
use App\Models\Departure;
use App\Queries\BookingStats;
use Illuminate\Database\Eloquent\Builder;
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

        // The next five dates of the operator's published tours (Exercise 10).
        $upcoming = Departure::query()
            ->upcoming()
            ->whereHas('tour', fn (Builder $tour) => $tour
                ->where('operator_id', $operator->id)
                ->where('status', TourStatus::Published))
            ->with('tour.city')
            ->orderBy('starts_on')
            ->orderBy('id')
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
            // Each date with its tour, side by side: DepartureResource does not
            // embed its tour (see TourResource).
            'upcoming_departures' => $upcoming->map(fn (Departure $departure) => [
                'departure' => DepartureResource::make($departure),
                'tour' => TourResource::make($departure->tour),
            ]),
        ]]);
    }
}
