<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\Tour;
use App\Support\ImageUploader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function __construct(private readonly ImageUploader $images) {}

    /** GET /api/tours/{tour}/reviews: public, paginated. */
    public function index(int $tour): AnonymousResourceCollection
    {
        $tour = Tour::query()->visibleToPublic()->findOrFail($tour);

        return ReviewResource::collection(
            $tour->reviews()->with('user:id,name')->latest()->paginate(10),
        );
    }

    /*
    | POST /api/tours/{tour}/reviews (multipart: rating, comment, photo?)
    |
    | Layers of protection, from outside to inside:
    |   route middleware  → logged in, e-mail verified, rate limited
    |   Form Request      → rating 1-5, comment length, photo is a real image ≤ 5 MB
    |   ReviewPolicy      → a traveler with a confirmed booking, first review
    |   ImageUploader     → photo re-encoded, metadata stripped, random name
    |   unique index      → one review per user and tour, even under races
    */
    public function store(StoreReviewRequest $request, Tour $tour): JsonResponse
    {
        Gate::authorize('create', [Review::class, $tour]);

        $photoPath = $request->hasFile('photo')
            ? $this->images->store($request->file('photo'), 'reviews', field: 'photo')
            : null;

        $review = new Review($request->safe()->only(['rating', 'comment']));
        $review->photo_path = $photoPath;
        $review->user()->associate($request->user());
        $review->tour()->associate($tour);

        try {
            $review->save();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submissions: the policy passed for both, the
            // database's unique index stopped the second one.
            $this->images->delete($photoPath);

            throw ValidationException::withMessages(['comment' => 'You have already reviewed this tour.']);
        }

        return ReviewResource::make($review->load('user:id,name'))->response()->setStatusCode(201);
    }

    public function destroy(Review $review): Response
    {
        Gate::authorize('delete', $review);

        $photoPath = $review->photo_path;
        $review->delete();
        $this->images->delete($photoPath);

        return response()->noContent();
    }
}
