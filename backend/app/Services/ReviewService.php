<?php

namespace App\Services;

use App\Models\Review;
use App\Models\Tour;
use App\Models\User;
use App\Support\ImageUploader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/*
| Writing and deleting reviews with their photos (Exercise 8, D21).
|
| Files are not part of database transactions, so they need manual care,
| the same way as tour images in TourService:
|
|   create: the photos are stored first, outside the transaction (no lock
|           or transaction is held while files are written, invariant 14).
|           If anything fails afterwards, or one photo cannot be decoded,
|           every file already written is deleted again: a failed request
|           leaves no orphan files and no half-saved review.
|   delete: the files are deleted only after the database committed, so a
|           review that still exists never points to a missing file.
|
| Who may create or delete is decided before this is called (ReviewPolicy).
*/
final class ReviewService
{
    public function __construct(private readonly ImageUploader $images) {}

    /**
     * @param  array{rating: int, comment: string}  $data  validated StoreReviewRequest data
     * @param  list<UploadedFile>  $photos  at most three, validated
     *
     * @throws ValidationException when a photo is not a real image, or the user already reviewed the tour
     */
    public function create(User $author, Tour $tour, array $data, array $photos): Review
    {
        $paths = [];

        try {
            foreach (array_values($photos) as $index => $photo) {
                $paths[] = $this->images->store($photo, 'reviews', field: "photos.{$index}");
            }

            return DB::transaction(function () use ($author, $tour, $data, $paths) {
                $review = new Review(['rating' => $data['rating'], 'comment' => $data['comment']]);
                $review->user()->associate($author);
                $review->tour()->associate($tour);
                $review->save();

                foreach ($paths as $index => $path) {
                    // Positions 1..3 in upload order; the database refuses 4.
                    $review->photos()->make()->forceFill(['position' => $index + 1, 'path' => $path])->save();
                }

                return $review;
            });
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                $this->images->delete($path);
            }

            if ($e instanceof UniqueConstraintViolationException) {
                // Two simultaneous submissions: the policy passed for both, the
                // unique index on (tour_id, user_id) stopped the second one.
                throw ValidationException::withMessages(['comment' => 'You have already reviewed this tour.']);
            }

            throw $e;
        }
    }

    public function delete(Review $review): void
    {
        $paths = $review->photos()->pluck('path')->all();

        // The photo rows go with it (ON DELETE CASCADE).
        DB::transaction(fn () => $review->delete());

        foreach ($paths as $path) {
            $this->images->delete($path);
        }
    }
}
