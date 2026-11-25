<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\Tour;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/*
| Reviews: who may write and delete them, and what the public list shows.
|
| Photo uploads (Exercise 8) are tested further down in this class.
*/
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Tour $tour;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->tour = Tour::factory()->create();
    }

    /** A verified traveler with a confirmed booking on $this->tour. */
    private function bookedTraveler(string $name = 'Maya Khoury'): User
    {
        $traveler = User::factory()->create(['name' => $name]);
        Booking::factory()->for($traveler)->for($this->tour)->create();

        return $traveler;
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge(['rating' => 5, 'comment' => 'A wonderful day in the valley.'], $overrides);
    }

    private function reviewBy(User $author): Review
    {
        return Review::factory()->for($author)->for($this->tour)->create();
    }

    // --- Who may write a review -------------------------------------------

    public function test_guests_cannot_post_reviews(): void
    {
        $this->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_unverified_travelers_cannot_post_reviews(): void
    {
        $traveler = User::factory()->unverified()->create();
        Booking::factory()->for($traveler)->for($this->tour)->create();

        $this->actingAs($traveler)
            ->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_suspended_travelers_cannot_post_reviews(): void
    {
        $traveler = User::factory()->suspended()->create();
        Booking::factory()->for($traveler)->for($this->tour)->create();

        $this->actingAs($traveler)
            ->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_operators_cannot_review_tours(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_traveler_without_a_confirmed_booking_cannot_review(): void
    {
        $traveler = $this->traveler();
        Booking::factory()->for($traveler)->for($this->tour)->create(['status' => BookingStatus::Cancelled]);

        $this->actingAs($traveler)
            ->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only review tours you have booked.');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_traveler_can_review_a_tour_they_booked(): void
    {
        $traveler = $this->bookedTraveler();

        $this->actingAs($traveler)
            ->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload(['user_id' => 999]))
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);

        $review = Review::sole();
        $this->assertTrue($review->user->is($traveler), 'The author comes from the session, not the request.');
        $this->assertTrue($review->tour->is($this->tour));
    }

    public function test_a_traveler_can_review_a_tour_only_once(): void
    {
        $traveler = $this->bookedTraveler();
        $this->reviewBy($traveler);

        $this->actingAs($traveler)
            ->postJson("/api/tours/{$this->tour->id}/reviews", $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'You have already reviewed this tour.');

        $this->assertDatabaseCount('reviews', 1);
    }

    // --- What the public list shows ---------------------------------------

    public function test_reviews_show_a_short_author_name_and_the_raw_comment_text(): void
    {
        // React escapes text when rendering, so the API returns the comment
        // unchanged. Escaping it here too would show "&lt;" to readers.
        $review = $this->reviewBy($this->bookedTraveler('Maya Khoury'));
        $review->forceFill(['comment' => '<img src=x onerror=alert(1)> Great tour'])->save();

        $this->getJson("/api/tours/{$this->tour->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.author_name', 'Maya K.')
            ->assertJsonPath('data.0.comment', '<img src=x onerror=alert(1)> Great tour');
    }

    // --- Who may delete a review ------------------------------------------

    public function test_the_author_can_delete_their_review(): void
    {
        $author = $this->bookedTraveler();
        $review = $this->reviewBy($author);

        $this->actingAs($author)->deleteJson("/api/reviews/{$review->id}")->assertNoContent();

        $this->assertModelMissing($review);
    }

    public function test_an_admin_can_delete_any_review(): void
    {
        $review = $this->reviewBy($this->bookedTraveler());

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/reviews/{$review->id}")
            ->assertNoContent();

        $this->assertModelMissing($review);
    }

    public function test_other_travelers_cannot_delete_a_review(): void
    {
        $review = $this->reviewBy($this->bookedTraveler());

        $this->actingAs($this->traveler())->deleteJson("/api/reviews/{$review->id}")->assertForbidden();

        $this->assertModelExists($review);
    }

    // --- Photos (Exercise 8) ----------------------------------------------

    /** Multipart POST, as the browser sends it (postJson cannot carry files). */
    private function postReview(User $author, array $photos): TestResponse
    {
        return $this->actingAs($author)->post(
            "/api/tours/{$this->tour->id}/reviews",
            $this->validPayload(['photos' => $photos]),
            ['Accept' => 'application/json'],
        );
    }

    /** @return list<UploadedFile> real images of different widths, to tell them apart */
    private function images(int $count): array
    {
        return array_map(
            fn (int $i) => UploadedFile::fake()->image("photo{$i}.jpg", 200 + 100 * $i, 300),
            range(1, $count),
        );
    }

    private function assertNoFilesStored(): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return array<string, array{int}> */
    public static function photoCounts(): array
    {
        return ['one photo' => [1], 'two photos' => [2], 'three photos' => [3]];
    }

    #[DataProvider('photoCounts')]
    public function test_a_review_can_have_up_to_three_photos_stored_in_order(int $count): void
    {
        $response = $this->postReview($this->bookedTraveler(), $this->images($count))
            ->assertCreated()
            ->assertJsonCount($count, 'data.photos')
            ->assertJsonStructure(['data' => ['photos' => [['id', 'url']]]])
            ->assertJsonMissingPath('data.photo_url');

        $photos = Review::sole()->photos;
        $this->assertSame(range(1, $count), $photos->pluck('position')->all());

        foreach ($photos as $index => $photo) {
            Storage::disk('public')->assertExists($photo->path);
            // Re-encoded by ImageUploader, so the upload order is checked by width.
            [$width] = getimagesizefromstring(Storage::disk('public')->get($photo->path));
            $this->assertSame(200 + 100 * ($index + 1), $width, 'Photos keep the order they were uploaded in.');
            $response->assertJsonPath("data.photos.{$index}.id", $photo->id);
        }
    }

    public function test_a_fourth_photo_is_refused_and_nothing_is_stored(): void
    {
        $this->postReview($this->bookedTraveler(), $this->images(4))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos' => 'You can add up to 3 photos.']);

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_photos', 0);
        $this->assertNoFilesStored();
    }

    public function test_svg_photos_are_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');

        $this->postReview($this->bookedTraveler(), [$svg])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos.0' => 'Photo 1 is not an image.']);

        $this->assertDatabaseCount('reviews', 0);
        $this->assertNoFilesStored();
    }

    public function test_a_php_script_disguised_as_a_photo_is_rejected(): void
    {
        $script = UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["cmd"]); ?>');

        $this->postReview($this->bookedTraveler(), [$script])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos.0');

        $this->assertDatabaseCount('reviews', 0);
        $this->assertNoFilesStored();
    }

    public function test_when_one_photo_cannot_be_decoded_no_file_and_no_review_is_left(): void
    {
        // Named .jpg, so it passes the `image` rule in tests (fake uploads get
        // their MIME type from the name) and only fails when GD decodes it.
        // Photo 1 is already on disk by then and must be removed again.
        [$good] = $this->images(1);
        $broken = UploadedFile::fake()->createWithContent('broken.jpg', 'not really an image');

        $this->postReview($this->bookedTraveler(), [$good, $broken])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos.1');

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_photos', 0);
        $this->assertNoFilesStored();
    }

    public function test_when_saving_the_review_fails_the_stored_photos_are_deleted(): void
    {
        // Two simultaneous submissions: the policy passed for both, the
        // unique index refuses the second one after its photos were stored.
        // The policy is skipped here by calling the service directly.
        $traveler = $this->bookedTraveler();
        $this->reviewBy($traveler);

        try {
            app(ReviewService::class)->create($traveler, $this->tour, $this->validPayload(), $this->images(2));
            $this->fail('The duplicate review should have been refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->errors());
        }

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseCount('review_photos', 0);
        $this->assertNoFilesStored();
    }

    /** @return array<string, array{string}> */
    public static function deleters(): array
    {
        return ['the author' => ['author'], 'an admin' => ['admin']];
    }

    #[DataProvider('deleters')]
    public function test_deleting_a_review_deletes_its_photo_rows_and_files(string $who): void
    {
        $author = $this->bookedTraveler();
        $this->postReview($author, $this->images(2))->assertCreated();
        $this->assertCount(2, Storage::disk('public')->allFiles());

        $deleter = $who === 'admin' ? User::factory()->admin()->create() : $author;
        $this->actingAs($deleter)->deleteJson('/api/reviews/'.Review::sole()->id)->assertNoContent();

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_photos', 0);
        $this->assertNoFilesStored();
    }

    public function test_review_photos_are_listed_in_order_without_extra_queries(): void
    {
        $review = $this->reviewBy($this->bookedTraveler());
        // Inserted out of order on purpose: the list must sort by position.
        foreach ([3, 1, 2] as $position) {
            ReviewPhoto::factory()->for($review)->create(['position' => $position, 'path' => "reviews/p{$position}.webp"]);
        }

        $queriesForOneReview = $this->countQueries(fn () => $this->getJson("/api/tours/{$this->tour->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.photos.0.url', Storage::disk('public')->url('reviews/p1.webp'))
            ->assertJsonPath('data.0.photos.1.url', Storage::disk('public')->url('reviews/p2.webp'))
            ->assertJsonPath('data.0.photos.2.url', Storage::disk('public')->url('reviews/p3.webp')));

        foreach (range(1, 4) as $i) {
            ReviewPhoto::factory()->for($this->reviewBy($this->bookedTraveler("Traveler {$i}")))->create();
        }

        $queriesForFiveReviews = $this->countQueries(fn () => $this->getJson("/api/tours/{$this->tour->id}/reviews")
            ->assertOk()
            ->assertJsonCount(5, 'data'));

        $this->assertSame($queriesForOneReview, $queriesForFiveReviews, 'Photos must be eager-loaded, not queried per review.');
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // --- The database (defence in depth) ----------------------------------

    public function test_the_database_refuses_a_fourth_photo_position(): void
    {
        $review = $this->reviewBy($this->bookedTraveler());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('review_photos_position_range');

        DB::table('review_photos')->insert([
            'review_id' => $review->id, 'position' => 4, 'path' => 'reviews/x.webp',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_two_photos_in_the_same_position(): void
    {
        $review = $this->reviewBy($this->bookedTraveler());
        ReviewPhoto::factory()->for($review)->create(['position' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        ReviewPhoto::factory()->for($review)->create(['position' => 1]);
    }

    // --- The data migration (one photo_path → review_photos) --------------

    private function photoMigration(): Migration
    {
        return require database_path('migrations/2026_11_25_000400_move_review_photos_out_of_reviews.php');
    }

    public function test_the_migration_moves_existing_photos_into_review_photos(): void
    {
        // Rebuild the old shape, add a review with a photo as it existed
        // before this change, then run the migration forward. Postgres runs
        // schema changes inside the test's transaction, so all of it is
        // rolled back afterwards.
        $migration = $this->photoMigration();
        $migration->down();
        $withPhoto = $this->reviewBy($this->bookedTraveler());
        $withoutPhoto = $this->reviewBy($this->bookedTraveler('Karim Haddad'));
        DB::table('reviews')->where('id', $withPhoto->id)->update(['photo_path' => 'reviews/old.webp']);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('reviews', 'photo_path'));
        $this->assertSame(
            [['review_id' => $withPhoto->id, 'position' => 1, 'path' => 'reviews/old.webp']],
            DB::table('review_photos')->get(['review_id', 'position', 'path'])->map(fn ($row) => (array) $row)->all(),
        );
        $this->assertSame(0, DB::table('review_photos')->where('review_id', $withoutPhoto->id)->count());
    }

    public function test_rolling_the_migration_back_restores_the_first_photo(): void
    {
        $review = $this->reviewBy($this->bookedTraveler());
        ReviewPhoto::factory()->for($review)->create(['position' => 2, 'path' => 'reviews/second.webp']);
        ReviewPhoto::factory()->for($review)->create(['position' => 1, 'path' => 'reviews/first.webp']);

        $this->photoMigration()->down();

        $this->assertSame('reviews/first.webp', DB::table('reviews')->where('id', $review->id)->value('photo_path'));
    }
}
