<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TourResource;
use App\Models\Tour;
use App\Services\TourService;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/*
| Content moderation: admins see every tour and can publish or archive it.
| Archiving also locks the status against the operator (TourService::moderate).
*/
class TourController extends Controller
{
    public function __construct(private readonly TourService $tours) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TourStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $tours = Tour::query()
            ->with(['city.region', 'category', 'operator.operatorProfile'])
            ->withCount('bookings')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $term) => $query->whereLike('title', Search::containsPattern($term)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return TourResource::collection($tours);
    }

    public function updateStatus(Request $request, Tour $tour): TourResource
    {
        Gate::authorize('moderate', $tour);

        $data = $request->validate(['status' => ['required', Rule::enum(TourStatus::class)]]);

        $tour = $this->tours->moderate($tour, TourStatus::from($data['status']));

        return TourResource::make($tour->load(['city.region', 'category', 'operator.operatorProfile'])->loadCount('bookings'));
    }
}
