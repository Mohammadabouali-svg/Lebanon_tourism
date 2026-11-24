# Exercise 1: Meeting point

## What was asked

> Add an optional `meeting_point` text to tours. You will touch: a new
> migration, `Tour` fillable, `TourRequest`, `TourResource`, the frontend
> type and form, the detail page, and a test.
> ([10-exercises.md](../10-exercises.md))

The goal is to follow one new field through every layer of the application,
from the database to the screen.

## Decisions

- **Optional (nullable).** Many tours include a hotel pickup, so there is
  often no meeting point. Nullable also means existing tours need no
  backfill: they simply get `NULL`.
- **One representation for "none".** An emptied form field arrives as `''`;
  Laravel's `ConvertEmptyStringsToNull` middleware turns it into `NULL`, so
  "no meeting point" is never stored as both `''` and `NULL`.
- **Max 255 characters**, both in the column (`string(255)`) and in
  `TourRequest`, so the user gets a readable 422 instead of a database error.
  A meeting point is a place and a time, not an essay.
- **A new migration**, not an edit of `create_tours_table`: that migration
  has already run on every database, so editing it would change nothing there
  (invariant 16).
- **Public field.** Travelers need it, so it is in `TourResource` for
  everyone. Operators can set it (it is in `#[Fillable]`) because, unlike
  `operator_id` or `booked_spots`, it is not a sensitive column.

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `backend/database/migrations/2026_11_10_000100_add_meeting_point_to_tours.php` | `string('meeting_point', 255)->nullable()`, with a working `down()` |
| Model | `backend/app/Models/Tour.php` | Added to `#[Fillable]`; `$attributes` default `null` (strict mode) |
| Validation | `backend/app/Http/Requests/TourRequest.php` | `['nullable', 'string', 'max:255']`, attribute name "meeting point" |
| Output | `backend/app/Http/Resources/TourResource.php` | `meeting_point` in the JSON |
| Type | `frontend/src/lib/types.ts` | `meeting_point: string \| null` |
| Operator form | `frontend/src/features/operator/TourFormPage.tsx` | Optional text field, server errors shown |
| Public page | `frontend/src/features/tours/TourDetailPage.tsx` | "Meeting point: ..." shown only when set |

## Tests

In `backend/tests/Feature/Operator/TourManagementTest.php`:

- `test_an_operator_can_give_a_tour_a_meeting_point`
- `test_the_meeting_point_is_optional`
- `test_an_emptied_meeting_point_is_stored_as_null`
- `test_a_meeting_point_longer_than_255_characters_is_refused`

In `backend/tests/Feature/TourCatalogTest.php`:

- `test_the_public_tour_page_shows_the_meeting_point`

## Verification

Verified on 2026-11-24: the full suite passed (90 tests), along with the
frontend build, lint and Pint. The meeting point was shown on the public
tour page.
