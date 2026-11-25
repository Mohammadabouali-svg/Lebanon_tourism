<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\Tour;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /** GET /api/tours/{tour}/reviews: public, paginated. */
    public function index(int $tour): AnonymousResourceCollection
    {
        $tour = Tour::query()->visibleToPublic()->findOrFail($tour);

        return ReviewResource::collection(
            // `photos` eager-loaded: one query for the whole page, not one per review.
            $tour->reviews()->with(['user:id,name', 'photos'])->latest()->paginate(10),
        );
    }

    /*
    | POST /api/tours/{tour}/reviews (multipart: rating, comment, photos[]?)
    |
    | Layers of protection, from outside to inside:
    |   route middleware  → logged in, e-mail verified, rate limited
    |   Form Request      → rating 1-5, comment length, ≤ 3 real images ≤ 5 MB each
    |   ReviewPolicy      → a traveler with a confirmed booking, first review
    |   ReviewService     → photos re-encoded (ImageUploader), cleaned up on failure
    |   database          → one review per user and tour, at most 3 photos
    */
    public function store(StoreReviewRequest $request, Tour $tour): JsonResponse
    {
        Gate::authorize('create', [Review::class, $tour]);

        $review = $this->reviews->create(
            author: $request->user(),
            tour: $tour,
            data: $request->safe()->only(['rating', 'comment']),
            photos: $request->file('photos', []),
        );

        return ReviewResource::make($review->load(['user:id,name', 'photos']))->response()->setStatusCode(201);
    }

    public function destroy(Review $review): Response
    {
        Gate::authorize('delete', $review);

        $this->reviews->delete($review);

        return response()->noContent();
    }
}
