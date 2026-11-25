# Exercise 3: "Sold out" badge

## What was asked

> The API already returns `available_spots` and `is_bookable`. Show a badge
> on tour cards and hide the book button. Pure frontend.
> ([10-exercises.md](../10-exercises.md))

## What we found first

Most of the exercise was already done:

| Part | State before | Where |
|---|---|---|
| API fields | `available_spots` and `is_bookable` (published **and** not started **and** `available_spots > 0`) | `TourResource.php` |
| TypeScript type | Both fields present | `lib/types.ts` |
| Hide the book button | Already done: "This tour is fully booked." instead of the button | `TourDetailPage.tsx` (`BookingAction`) |
| Booking page | Already shows "Booking unavailable" when `!is_bookable` | `BookingPage.tsx` |
| Card | Plain grey text "Sold out" in the bottom-right corner, **no badge** | `TourCard.tsx` |
| Backend tests | **None** asserted `is_bookable` or that a full tour stays listed | `backend/tests` |

The card has no book button of its own: the whole card is one link to the
detail page.

One more fact shaped the decisions: the public list
(`TourController::index`) only returns tours that are **published and
upcoming** (`visibleToPublic()->upcoming()`). So on a card, `!is_bookable`
and `available_spots === 0` mean the same thing today.

So the real gap was the visual badge, plus a test for the API behaviour the
badge depends on.

## Decisions

| Question | Choice | Why |
|---|---|---|
| Condition for the badge | **`available_spots === 0`**, not `!is_bookable` | The label says "Sold out", so the condition should mean exactly that. `is_bookable` is also false for draft or started tours, which are not sold out. In the list both agree today; this keeps the label honest if the list ever shows started tours. No badge for started or draft tours, which never reach a card. |
| Placement | **Top-right corner of the image**, existing `Badge` with `tone="red"` | Seen first when scanning a grid. The bottom-right "Sold out" text is **kept**, so the spots line never goes empty and the layout doesn't jump. |
| Muted card, still a link? | **Image at 60% opacity, card still links** to the detail page | The detail page is still useful (itinerary, reviews, meeting point) and explains "This tour is fully booked." |
| Accessibility | The badge is **real text**, not colour alone | Screen readers read "Sold out" as part of the card. The card link's accessible name stays the tour title. |
| "Hide the book button" | **Nothing left to do** | Already handled on the detail and booking pages. The API also refuses the booking (`not_enough_spots`), since the UI is not security (invariant 3). |
| Frontend test runner (Vitest + Testing Library)? | **No**, no new dependency | AGENTS.md §1: a dependency is a decision. One `Badge` and one condition don't justify a test toolchain. Verified with build, lint and a manual check. The behaviour the badge relies on is pinned by **backend** tests instead. No ADR, since nothing was added. |

## What was done

| Layer | File | Change |
|---|---|---|
| Component | `frontend/src/features/tours/TourCard.tsx` | `soldOut` constant (with a *why* comment); `Badge` over the image; image muted when sold out; existing text reuses `soldOut` |
| Tests | `backend/tests/Feature/TourCatalogTest.php` | 2 new tests (below) |
| Docs | `docs/07-frontend.md` (7.9), `docs/10-exercises.md`, this write-up and its README row, `AGENTS.md` §11 | Pattern note ("display server-decided flags"), exercise marked implemented, status set to done |

No migration, model, request, Resource, route or TypeScript type changed.

## Tests

In `backend/tests/Feature/TourCatalogTest.php`:

- `test_a_sold_out_tour_is_still_listed_but_not_bookable`: a tour with
  capacity 8 and 8 booked spots **is in** `GET /api/tours`, with
  `available_spots = 0` and `is_bookable = false`. Without the first part,
  the badge would be useless: a list that filtered out full tours would
  never show it.
- `test_a_tour_with_spots_left_is_bookable`: one spot left →
  `available_spots = 1`, `is_bookable = true`.

### Red?

These are **characterisation tests**: the behaviour already existed, so
they passed on their first run. That is reported honestly rather than
presented as red → green.

To prove the tests can fail, a **mutation check** was done: in
`TourResource` the condition `available_spots > 0` was temporarily changed
to `>= 0`:

```
⨯ a sold out tour is still listed but not bookable
  Failed asserting that true is identical to false.
```

The change was then reverted (`git checkout` of that file only).

## Verification (2026-11-25)

| Check | Result |
|---|---|
| Full backend suite | 95 passed (311 assertions) |
| `TourCatalogTest` | 9 passed (27 assertions) |
| Pint `--test` | pass (139 files) |
| Frontend build (`tsc -b` + vite) | pass |
| Frontend lint (oxlint) | 0 warnings, 0 errors |
| Manual, guest, `/tours` | Badge on the sold-out card only, image muted; clicking the badge opens the detail page |
| Manual, guest, `/` (home) | Badge on the sold-out card in "Upcoming departures" |
| Manual, guest, detail page | "Sold out" + "This tour is fully booked.", no "Log in to book" |
| Manual, `traveler@`, detail page | "This tour is fully booked.", no "Book this tour" |
| Manual, `traveler@`, `/tours/9/book` typed directly | "Booking unavailable" |
| Manual, `traveler@`, `/tours` | Only the two sold-out tours have the badge |
| Browser console | Only the expected guest 401s on `/api/user` |

Dev data used for the manual check, then restored:

| Tour | Original `booked_spots` | Set to | Restored |
|---|---|---|---|
| 5 "Cedars Snowshoe Weekend" | 0 | 10 (capacity) | 0 ✔ |
| 9 "Tripoli Souks & Street Food" | 3 | 15 (capacity) | 3 ✔ |

Tour 9 was needed because the home page only shows the 6 soonest
departures, and tour 5 is not among them. Spots were set directly in SQL
(dev database only) instead of making real bookings, so no booking or
wallet rows were created.

Not verified: operator and admin views (cards are only used on the public
pages), and screen-reader output with a real screen reader (the badge's
text was checked in the DOM).

## Things to know

- If the public list ever starts showing started tours, they will show
  no badge on the card (they are not sold out); only the detail page would
  say "This tour is not open for booking." A separate "Departed" badge
  could be added then.
