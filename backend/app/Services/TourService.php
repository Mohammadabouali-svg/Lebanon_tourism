<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Tour;
use App\Models\User;
use App\Support\ImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
| Creating and updating tours (operator area).
|
| A tour and its itinerary are saved together in one transaction: a tour
| without its itinerary (or with half of it) can never be observed.
|
| Files are not part of database transactions, so they need manual care:
|   - the new image is stored first; if the database work fails, it is
|     deleted again (no orphan files);
|   - the old image is deleted only after the database committed (no tour
|     pointing to a deleted file).
*/
final class TourService
{
    public function __construct(private readonly ImageUploader $images) {}

    /** @param  array<string, mixed>  $data  validated TourRequest data */
    public function create(User $operator, array $data, ?UploadedFile $image): Tour
    {
        $tour = new Tour;
        $tour->operator()->associate($operator);

        return $this->save($tour, $data, $image);
    }

    /** @param  array<string, mixed>  $data  validated TourRequest data */
    public function update(Tour $tour, array $data, ?UploadedFile $image): Tour
    {
        return $this->save($tour, $data, $image);
    }

    /** @throws BusinessRuleException when the tour has bookings */
    public function delete(Tour $tour): void
    {
        if ($tour->bookings()->exists()) {
            throw BusinessRuleException::tourHasBookings();
        }

        $imagePath = $tour->image_path;
        $tour->delete(); // itinerary items are removed by ON DELETE CASCADE
        $this->images->delete($imagePath);
    }

    private function save(Tour $tour, array $data, ?UploadedFile $image): Tour
    {
        $oldImagePath = $tour->image_path;
        $newImagePath = $image ? $this->images->store($image, 'tours') : null;

        try {
            DB::transaction(function () use ($tour, $data, $newImagePath) {
                if ($tour->exists) {
                    // Lock so that no booking slips in between this check and the save.
                    $bookedSpots = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->value('booked_spots');

                    if ($data['capacity'] < $bookedSpots) {
                        throw BusinessRuleException::capacityBelowBookings($bookedSpots);
                    }
                }

                $tour->fill(Arr::except($data, ['itinerary', 'image']));
                if ($newImagePath !== null) {
                    $tour->image_path = $newImagePath;
                }
                $tour->save();

                // Replacing the whole itinerary is simpler than diffing old and
                // new items, and itineraries are small.
                $tour->itineraryItems()->delete();
                $tour->itineraryItems()->createMany($data['itinerary']);
            });
        } catch (Throwable $e) {
            $this->images->delete($newImagePath);

            throw $e;
        }

        if ($newImagePath !== null && $oldImagePath !== null) {
            $this->images->delete($oldImagePath);
        }

        // Read back columns the database computed (duration_days).
        return $tour->refresh();
    }
}
