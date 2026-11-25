<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Departure;
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

    /** What every booking response shows: the tour, the departure (with its tour, for ends_on) and the code. */
    private const RELATIONS = ['tour.city', 'tour.category', 'departure.tour', 'discount'];

    /** The logged-in traveler's bookings, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = $request->user()->bookings()
            ->with(self::RELATIONS)
            ->latest('id')
            ->paginate(20);

        return BookingResource::collection($bookings);
    }

    /*
    | POST /api/departures/{departure}/quote: the exact price the server
    | would charge.
    |
    | The checkout page calls this whenever the traveler changes the number
    | of spots or the discount code. The browser never calculates prices.
    */
    public function quote(Request $request, Departure $departure): JsonResponse
    {
        // Draft tours of other operators must not leak their prices.
        $tour = Tour::query()->visibleToPublic()->find($departure->tour_id);
        abort_if($tour === null, 404);

        // Two fields only, so inline validation instead of a Form Request class.
        $data = $request->validate([
            'spots' => ['required', 'integer', 'min:1', 'max:20'],
            'discount_code' => ['nullable', 'string', 'max:40'],
        ]);

        $quote = $this->pricing->quote($tour, (int) $data['spots'], $data['discount_code'] ?? null, $request->user());

        return response()->json(['data' => [
            ...$quote->toArray(),
            'available_spots' => $departure->available_spots,
        ]]);
    }

    /*
    | POST /api/departures/{departure}/bookings. The tour is not in the URL:
    | it is the departure's tour, so a client cannot send a mismatching pair.
    */
    public function store(StoreBookingRequest $request, Departure $departure): JsonResponse
    {
        $booking = $this->bookings->book(
            user: $request->user(),
            departure: $departure,
            spots: $request->integer('spots'),
            method: PaymentMethod::from($request->validated('payment_method')),
            discountCode: $request->validated('discount_code'),
        );

        // Sent after the transaction committed, through the queue.
        if ($booking->status === BookingStatus::Confirmed) {
            $request->user()->notify(new BookingConfirmedNotification($booking));
        }

        return BookingResource::make($booking->load(self::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(Booking $booking): BookingResource
    {
        Gate::authorize('cancel', $booking);

        $booking = $this->bookings->cancel($booking);

        return BookingResource::make($booking->load(self::RELATIONS));
    }
}
