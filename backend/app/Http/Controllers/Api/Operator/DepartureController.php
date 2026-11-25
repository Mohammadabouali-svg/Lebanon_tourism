<?php

namespace App\Http\Controllers\Api\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepartureRequest;
use App\Http\Requests\UpdateDepartureRequest;
use App\Http\Resources\DepartureResource;
use App\Models\Departure;
use App\Models\Tour;
use App\Services\DepartureService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/*
| The dates of the operator's own tours (Exercise 10, D23). The route group
| guarantees an approved operator; TourPolicy / DeparturePolicy guarantee
| the tour is theirs (in the Form Requests' authorize() for store/update).
*/
class DepartureController extends Controller
{
    public function __construct(private readonly DepartureService $departures) {}

    /** Every departure, past ones included, with its number of bookings. */
    public function index(Tour $tour): AnonymousResourceCollection
    {
        Gate::authorize('update', $tour);

        // Loaded through the tour, so chaperone() gives each departure its tour.
        return DepartureResource::collection($tour->departures()->withCount('bookings')->get());
    }

    public function store(StoreDepartureRequest $request, Tour $tour): JsonResponse
    {
        $departures = $this->departures->create($tour, $request->dates(), $request->integer('capacity'));

        return DepartureResource::collection($departures)->response()->setStatusCode(201);
    }

    public function update(UpdateDepartureRequest $request, Departure $departure): DepartureResource
    {
        $departure = $this->departures->update(
            departure: $departure,
            startsOn: CarbonImmutable::parse($request->validated('starts_on')),
            capacity: $request->integer('capacity'),
        );

        return DepartureResource::make($departure);
    }

    public function destroy(Departure $departure): Response
    {
        Gate::authorize('delete', $departure);

        $this->departures->delete($departure);

        return response()->noContent();
    }
}
