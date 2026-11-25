<?php

namespace App\Services;

use App\Enums\TourStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tour;
use App\Models\User;
use App\Support\ImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
| Creating and updating tours (operator area), and the admin's moderation
| of them (moderate(), with the lock that save() enforces).
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

    /**
     * An admin changes a tour's status (content moderation).
     *
     * Archiving locks the tour: `moderated_at` is set and the operator can no
     * longer change the status (see save()). Any other status the admin picks
     * reinstates the tour and removes the lock. This lives here, next to the
     * operator's side of the rule, rather than in a policy or Form Request:
     * see docs/decisions.md D19.
     */
    public function moderate(Tour $tour, TourStatus $status): Tour
    {
        DB::transaction(function () use ($tour, $status) {
            // Same row lock as save(): an operator save and an admin decision
            // on the same tour run one after the other, never interleaved.
            $current = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail(['moderated_at']);

            // forceFill: moderated_at is deliberately not fillable (invariant 9).
            $tour->forceFill([
                'status' => $status,
                // Re-archiving a locked tour keeps the original lock time.
                'moderated_at' => $status === TourStatus::Archived ? ($current->moderated_at ?? now()) : null,
            ])->save();
        });

        return $tour;
    }

    /** @throws BusinessRuleException when the tour has bookings */
    public function delete(Tour $tour): void
    {
        if ($tour->bookings()->exists()) {
            throw BusinessRuleException::tourHasBookings();
        }

        $imagePath = $tour->image_path;
        $tour->delete(); // itinerary items and (booking-free) departures go by ON DELETE CASCADE
        $this->images->delete($imagePath);
    }

    private function save(Tour $tour, array $data, ?UploadedFile $image): Tour
    {
        $oldImagePath = $tour->image_path;
        $newImagePath = $image ? $this->images->store($image, 'tours') : null;

        try {
            DB::transaction(function () use ($tour, $data, $newImagePath) {
                if ($tour->exists) {
                    // Lock so that no admin moderation (and no booking, which
                    // holds FOR SHARE on the tour while it reads the price and
                    // status) slips in between this check and the save. The
                    // values checked are read from the locked row, not from
                    // $tour, which was loaded before the lock and may be stale.
                    // Capacity is per departure now: DepartureService guards it.
                    $current = Tour::query()->whereKey($tour->getKey())->lockForUpdate()
                        ->firstOrFail(['status', 'moderated_at']);

                    // The admin moderation lock (D19). Sending the current status
                    // again is allowed: the edit form always sends one.
                    if ($current->moderated_at !== null && TourStatus::from($data['status']) !== $current->status) {
                        throw BusinessRuleException::tourModerated();
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

        // Read back the saved row (timestamps, defaults set by the database).
        return $tour->refresh();
    }
}
