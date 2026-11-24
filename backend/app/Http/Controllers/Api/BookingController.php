<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Tour;
use App\Notifications\BookingConfirmedNotification;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/*
| A thin controller: it translates HTTP into a call to BookingService and
| the result back into JSON. No business rule lives here.
*/
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly PricingService $pricing,
    ) {}

    /** The logged-in traveler's bookings, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = $request->user()->bookings()
            ->with(['tour.city', 'tour.category', 'discount'])
            ->latest('id')
            ->paginate(20);

        return BookingResource::collection($bookings);
    }

    /*
    | POST /api/tours/{tour}/quote: the exact price the server would charge.
    |
    | The checkout page calls this whenever the traveler changes the number
    | of spots or the discount code. The browser never calculates prices.
    */
    public function quote(Request $request, Tour $tour): JsonResponse
    {
        // Draft tours of other operators must not leak their prices.
        abort_unless(Tour::query()->visibleToPublic()->whereKey($tour->getKey())->exists(), 404);

        // Two fields only, so inline validation instead of a Form Request class.
        $data = $request->validate([
            'spots' => ['required', 'integer', 'min:1', 'max:20'],
            'discount_code' => ['nullable', 'string', 'max:40'],
        ]);

        $quote = $this->pricing->quote($tour, (int) $data['spots'], $data['discount_code'] ?? null, $request->user());

        return response()->json(['data' => [
            ...$quote->toArray(),
            'available_spots' => $tour->available_spots,
        ]]);
    }

    public function store(StoreBookingRequest $request, Tour $tour): JsonResponse
    {
        $booking = $this->bookings->book(
            user: $request->user(),
            tour: $tour,
            spots: $request->integer('spots'),
            method: PaymentMethod::from($request->validated('payment_method')),
            discountCode: $request->validated('discount_code'),
        );

        // Sent after the transaction committed, through the queue.
        if ($booking->status === BookingStatus::Confirmed) {
            $request->user()->notify(new BookingConfirmedNotification($booking));
        }

        return BookingResource::make($booking->load(['tour.city', 'tour.category', 'discount']))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(Booking $booking): BookingResource
    {
        Gate::authorize('cancel', $booking);

        $booking = $this->bookings->cancel($booking);

        return BookingResource::make($booking->load(['tour.city', 'tour.category', 'discount']));
    }
}
