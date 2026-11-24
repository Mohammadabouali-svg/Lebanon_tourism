<?php

namespace App\Http\Controllers\Api\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\TourRequest;
use App\Http\Resources\TourResource;
use App\Models\Tour;
use App\Services\TourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/*
| The operator's own tours. The route group (routes/api.php) guarantees the
| user is an approved operator; TourPolicy guarantees the tour is theirs.
*/
class TourController extends Controller
{
    public function __construct(private readonly TourService $tours) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Starting the query from the user's relation scopes it to their
        // tours: there is no way to accidentally list someone else's.
        $tours = $request->user()->tours()
            ->with(['city.region', 'category'])
            ->withCount('bookings')
            ->latest('starts_on')
            ->paginate(20);

        return TourResource::collection($tours);
    }

    public function show(Tour $tour): TourResource
    {
        Gate::authorize('update', $tour);

        return TourResource::make($tour->load(['city.region', 'category', 'itineraryItems']));
    }

    public function store(TourRequest $request): JsonResponse
    {
        $tour = $this->tours->create($request->user(), $request->validated(), $request->file('image'));

        return TourResource::make($tour->load(['city.region', 'category', 'itineraryItems']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(TourRequest $request, Tour $tour): TourResource
    {
        Gate::authorize('update', $tour);

        $tour = $this->tours->update($tour, $request->validated(), $request->file('image'));

        return TourResource::make($tour->load(['city.region', 'category', 'itineraryItems']));
    }

    public function destroy(Tour $tour): Response
    {
        Gate::authorize('delete', $tour);

        $this->tours->delete($tour);

        return response()->noContent();
    }
}
