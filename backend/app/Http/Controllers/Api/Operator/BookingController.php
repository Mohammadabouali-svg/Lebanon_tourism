<?php

namespace App\Http\Controllers\Api\Operator;

use App\Exports\BookingsExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bookings made on the operator's tours. */
class BookingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = $this->query($request)
            ->with(['tour.city', 'user', 'discount'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    public function export(Request $request): StreamedResponse
    {
        return BookingsExport::download($this->query($request), 'bookings-'.now()->format('Y-m-d').'.csv');
    }

    /** One query definition for both the list and the export. */
    private function query(Request $request): Builder
    {
        $filters = $request->validate([
            'tour_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,confirmed,cancelled,failed'],
        ]);

        return Booking::query()
            // The scope that matters: only bookings on tours this operator owns.
            ->whereHas('tour', fn (Builder $tour) => $tour->where('operator_id', $request->user()->id))
            ->when($filters['tour_id'] ?? null, fn (Builder $query, $tourId) => $query->where('tour_id', $tourId))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status));
    }
}
