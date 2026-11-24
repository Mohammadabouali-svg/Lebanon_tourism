<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Exports\BookingsExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = $this->query($request)
            ->with(['tour.city', 'tour.operator.operatorProfile', 'user', 'discount'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    public function export(Request $request): StreamedResponse
    {
        return BookingsExport::download($this->query($request), 'all-bookings-'.now()->format('Y-m-d').'.csv');
    }

    private function query(Request $request): Builder
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            // Matches a booking reference or the traveler's name / e-mail.
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return Booking::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $term) {
                $pattern = Search::containsPattern($term);
                $query->where(fn (Builder $q) => $q
                    ->whereLike('reference', $pattern)
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->whereLike('name', $pattern)
                        ->orWhereLike('email', $pattern)));
            });
    }
}
