# Exercise 8: Review photos gallery

## What was asked

> Allow up to three photos per review. You need a `review_photos` table,
> array validation (`photos.*`), and cleanup of the files when a review is
> deleted. ([10-exercises.md](../10-exercises.md))

## What we found first

| Fact | Where |
|---|---|
| Reviews already had **one** optional photo: `reviews.photo_path`, rule `photo`, `photo_url` in the Resource. So this was a migration from one photo to up to three, not a new feature | reviews migration, `StoreReviewRequest`, `ReviewResource` |
| The controller stored the file, deleted it again when the unique index refused a duplicate review, and deleted it after `$review->delete()` | `ReviewController` |
| `reviews.tour_id` / `user_id` are `cascadeOnDelete`, but the cascades cannot fire with reviews present: users are never deleted, `TourService::delete()` refuses a tour with bookings, a review needs a confirmed booking, and `bookings.tour_id` is `restrictOnDelete` | migrations, `TourService` |
| 3 × 5 MB did not fit: PHP `post_max_size = 10M`, nginx `client_max_body_size 10m` (dev and prod) | `docker/php/conf.d/app.ini`, `docker/nginx/*.conf` |
| **No review tests existed**, although `docs/06-security.md` and `docs/08-testing.md` cited `ReviewTest` and two of its tests by name | `backend/tests` |
| Dev DB: 3 reviews, none with a photo; `traveler@` had a confirmed booking on tour 4 without a review | dev database |

All hand-off findings held when checked. The missing `ReviewTest` was worse
than reported: the docs pointed to tests that did not exist. They exist now,
under the cited names.

## Decisions

Full version: **D21** in [decisions.md](../decisions.md).

| Question | Choice | Rejected alternative and why |
|---|---|---|
| Data model | `review_photos (review_id, position, path)`, FK cascade | `photo_1..3` columns: count and order become code, not data |
| "Max 3" in the database | CHECK `review_photos_position_range` (1..3) + unique `(review_id, position)` | Trigger: more to explain, racy without a lock. Validation only (D17 style): the cap is part of the storage shape and costs nothing to enforce |
| Old `photo_path` | Copied as position 1 (`INSERT ... SELECT`) and dropped, in its own migration; `down()` copies position 1 back | Keeping it: two sources of truth |
| API contract | `photo_url` → `photos: [{id, url}]`; `photo` → `photos[]`, no transition | Both fields: dead code for a client that does not exist (our app ships with the API) |
| Size | 5 MB per photo kept; request limit 20 MB (PHP + nginx dev/prod) | 3 MB per photo: would refuse ordinary phone photos |
| Failure halfway | New `ReviewService::create()`: store files first, insert review + photos in one transaction, delete every written file on any failure | Logic in the controller: business logic in the HTTP layer (§5) |
| Delete | `ReviewService::delete()`: read paths, delete in a transaction (rows cascade), delete files after commit | Model `deleting` event: runs before the commit and hides a side effect |
| Cascades from tour/user | No extra code; documented as unreachable, and as a duty for any future delete feature | — |
| Edit photos later | Not in scope: only at creation (there is no review edit endpoint) | — |

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `backend/database/migrations/2026_11_25_000300_create_review_photos_table.php` | table, FK cascade, unique `(review_id, position)`, CHECK `review_photos_position_range` |
| Migration | `backend/database/migrations/2026_11_25_000400_move_review_photos_out_of_reviews.php` | copy `photo_path` → position 1, drop column; `down()` restores position 1 |
| Model | `backend/app/Models/ReviewPhoto.php` (new), `Review.php` | nothing fillable, `url` accessor; `Review::photos()` ordered by position; `photo_path` default and `photo_url` removed |
| Factory | `backend/database/factories/ReviewPhotoFactory.php` | row only, no file |
| Form Request | `backend/app/Http/Requests/StoreReviewRequest.php` | `photos` `nullable\|array\|max:3`, `photos.*` image rules; readable messages ("Photo 2 is not an image.") |
| Service | `backend/app/Services/ReviewService.php` (new) | `create()` and `delete()` with the file cleanup described above |
| Controller | `backend/app/Http/Controllers/Api/ReviewController.php` | thin: policy, then the service; list eager-loads `photos` |
| Resource | `backend/app/Http/Resources/ReviewResource.php` | `photos: [{id, url}]` instead of `photo_url` |
| Infra | `docker/php/conf.d/app.ini`, `docker/nginx/dev.conf`, `docker/nginx/prod.conf` | `post_max_size = 20M`, `client_max_body_size 20m` |
| Types | `frontend/src/lib/types.ts` | `ReviewPhoto`, `photos: ReviewPhoto[]` |
| Hook | `frontend/src/hooks/useFilePreviews.ts` (new) | several previews, URLs revoked on remove/clear/unmount |
| Page | `frontend/src/features/tours/ReviewsSection.tsx` | multiple file input (keeps 3, says how many were left out), previews with Remove, `photos[]`, errors from `photos` / `photos.N`, gallery with "Photo 2 of 3 by Maya K." alt text |
| Docs | `docs/04-database.md`, `06-security.md`, `07-frontend.md`, `08-testing.md`, `10-exercises.md`, `decisions.md` (D21), `AGENTS.md` §3, §10, §11, §14 (PHP ini needs an image rebuild), this write-up + README row | |

## Tests

New file `tests/Feature/ReviewTest.php`: 23 test methods, 26 tests counting
the data sets. The suite went from 134 to 160 tests.

**Characterisation** of the existing behaviour, written first and run against
the unchanged code. These are not red runs: they passed straight away, as
expected, because they describe what already worked.

| Test | Proves |
|---|---|
| `guests_cannot_post_reviews` | 401, nothing stored |
| `unverified_travelers_cannot_post_reviews` | 403 |
| `suspended_travelers_cannot_post_reviews` | 403 (`active` middleware; added during review) |
| `operators_cannot_review_tours` | 403 |
| `a_traveler_without_a_confirmed_booking_cannot_review` | 403 with the policy message (a cancelled booking does not count) |
| `a_traveler_can_review_a_tour_they_booked` | 201; author from the session, a hostile `user_id` is ignored |
| `a_traveler_can_review_a_tour_only_once` | 403, still one row |
| `reviews_show_a_short_author_name_and_the_raw_comment_text` | "Maya K.", comment returned unescaped (React escapes) |
| `the_author_can_delete_their_review` / `an_admin_can_delete_any_review` | 204, row gone |
| `other_travelers_cannot_delete_a_review` | 403, row kept |

**New behaviour.** Each test was written first and failed for the right reason
before the change:

| Test | Red before the change | Proves |
|---|---|---|
| `a_review_can_have_up_to_three_photos_stored_in_order` (1, 2, 3) | TypeError: no `photos` relation | rows at positions 1..n, files exist, upload order kept (checked by image width), `photo_url` gone |
| `a_fourth_photo_is_refused_and_nothing_is_stored` | 201 instead of 422 | 422 "You can add up to 3 photos.", no row, no file |
| `svg_photos_are_refused` | 201 instead of 422 | 422 "Photo 1 is not an image.", no file |
| `a_php_script_disguised_as_a_photo_is_rejected` | 201 instead of 422 | 422 on `photos.0`, no file |
| `when_one_photo_cannot_be_decoded_no_file_and_no_review_is_left` | 201 instead of 422 | photo 1 written, photo 2 fails in GD → photo 1 deleted, no rows |
| `when_saving_the_review_fails_the_stored_photos_are_deleted` | `ReviewService` did not exist | duplicate review (race) → 422 `comment`, both files deleted |
| `deleting_a_review_deletes_its_photo_rows_and_files` (author, admin) | 0 files where 2 were expected (photos ignored) | rows and files gone |
| `review_photos_are_listed_in_order_without_extra_queries` | `ReviewPhoto` did not exist | sorted by position although inserted 3, 1, 2; same query count for 1 and 5 reviews |
| `the_database_refuses_a_fourth_photo_position` | table did not exist | CHECK `review_photos_position_range` |
| `the_database_refuses_two_photos_in_the_same_position` | `ReviewPhoto` did not exist | unique `(review_id, position)` |
| `the_migration_moves_existing_photos_into_review_photos` | migration file did not exist | old shape rebuilt with `down()`, a review with a `photo_path` migrated to position 1, column dropped, review without photo untouched |
| `rolling_the_migration_back_restores_the_first_photo` | `ReviewPhoto` did not exist | `down()` puts position 1 back into `photo_path` |

## Verification

| Check | Result |
|---|---|
| Suite before the change | 134 passed |
| Full suite after | **160 passed** (613 assertions) |
| Pint | pass, no changes |
| Frontend build (`tsc -b` + vite) and lint (oxlint) | pass, 0 warnings |
| Migrations on the dev DB | `migrate`, `migrate:rollback --step=2`, `migrate` all ran; the 3 existing reviews kept |
| Config | `nginx` restarted (config is bind-mounted): `client_max_body_size 20m`. **`app.ini` is copied into the PHP image**, so a restart was not enough: `docker compose build php queue` + `up -d php queue`, then `post_max_size` read 20M |

Manual check in the browser as `traveler@` on tour 4 (confirmed booking #2,
no review before):

| Step | Result |
|---|---|
| A request with 4 photos sent straight to the API (14.4 MB in total, more than the old 10 MB limit) | passed nginx and PHP, **422** "You can add up to 3 photos.", no row, no file |
| 4 photos chosen in the form | 3 previews kept, "Only 3 photos can be added; 1 left out.", file input disabled |
| Review published with 3 photos (3.6 MB PNG each) | 201; rows at positions 1-3; 3 WebP files (~780 KB each, re-encoded) in `backend/storage/app/public/reviews` |
| Gallery on the tour page | 3 images in order, alt "Photo 1 of 3 by Maya K." ..., all loaded |
| Review deleted from the page (confirm dialog says "its photos") | 204; review row, 3 photo rows and 3 files gone; list shows "No reviews yet" |
| Console | only the deliberate 422 |

**Data restored:** the test review is deleted (only reviews 1-3 remain, as
before), booking #2 is untouched, and the empty `reviews/` folder that the
check created was removed. The `reviews` id sequence moved on by one (4 was
used); that cannot be undone without a destructive reset. The browser was
signed back in as `traveler2@`, as it was before.

**Not verified:** the production stack (`compose.prod.yaml`, `prod.conf`) was
not run; its change is the same one-line `client_max_body_size`. Neither was a
request above 20 MB (expected: 413, generic message in the UI).

## Follow-ups (not done here)

- Adding or removing photos after a review is posted (needs a review edit endpoint).
- A friendly message for a 413 (request above 20 MB) in `parseApiError`.
- AGENTS.md §3 lists services but not `EmailChangeService` (from Exercise 4).
