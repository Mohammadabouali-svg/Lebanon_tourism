# Exercise 10: Recurring departures

## What was asked

> Today a tour has exactly one date. Real tour operators run the same tour
> every Saturday. Introduce a `departures` table (tour, date, capacity,
> booked_spots) and move booking and capacity there. This is a real
> data-model refactoring: plan the migration of existing data before writing
> code. ([10-exercises.md](../10-exercises.md))

Done out of the planned order (before 12, 11 and 9), by choice; AGENTS.md
§11 records the new order.

## What we found first

| Fact | Where |
|---|---|
| `tours` held `capacity`, `booked_spots`, `starts_on`, `ends_on`, a *generated* `duration_days`, CHECKs `tours_capacity_positive`, `tours_no_overbooking`, `tours_dates_ordered`, index `(status, starts_on)` | `2026_01_01_000300_create_tours_table` |
| `bookings.tour_id` restrict on delete, index `(tour_id, status)`, price snapshot | `2026_01_01_000500_...` |
| `BookingService` locked the tour row to count spots; `releaseSpots()`, `cancel()`, `expireStalePending()` (D18) and `markPaid()` all went through the tour counter | `BookingService` |
| `TourService::save()` refused capacity below `booked_spots` under the tour lock | `TourService` |
| Other consumers the hand-off did not list: `Booking::isCancellable()` (`tour->hasStarted()`), the export's `tour:id,title,starts_on`, the operator bookings filter on `tour_id`, `EmailVerificationTest` (old booking URL) | |
| `unsignedSmallInteger` is a plain signed `smallint` in Postgres: nothing stopped `booked_spots` from going **negative** | migrations |
| Dev data: 11 tours, 4 confirmed bookings (7 spots) on tours 1, 4, 9, 0 pending; every counter equal to its bookings | dev database |
| "A concurrency test exists": partly. The overbooking test sends requests one after the other; PHPUnit cannot run two in parallel inside `RefreshDatabase`'s single transaction | `BookingTest` |

## Decisions

Full version: **D23** in [decisions.md](../decisions.md).

| Question | Choice | Rejected alternative and why |
|---|---|---|
| a. What moves | `departures (tour_id, starts_on, capacity, booked_spots)`; price, itinerary, status and length stay on the tour; `duration_days` becomes a plain column, end date = `starts_on + duration_days − 1` | `starts_on` + `ends_on` per departure: a departure could disagree with the itinerary's length |
| b. `bookings.tour_id` | kept, guarded by a composite FK `(departure_id, tour_id) → departures (id, tour_id)` | Dropped: every tour-level query (reviews, scoping, export, stats) would join through departures |
| c. Data migration | expand → migrate → contract, three migrations, one release; copies in SQL; `down()` refuses when a tour has several (or no) departures | Two releases (right with live traffic, not here); a lossy `down()` |
| d. Constraints | capacity > 0, no overbooking, booked ≥ 0 (new), unique `(tour_id, starts_on)`; tour → departures cascade, bookings → departure restrict; `tours_duration_range` 1..30 | Partial rules: none needed |
| e. Who manages them | operator endpoints (list, add with optional `weeks` 1..26, change, delete), `DeparturePolicy` → `TourPolicy`; a departure with any booking keeps its date and cannot be deleted | Departures as an array in the tour form (replace-all would delete booked dates); stored recurrence rules (much more to build) |
| f. API contract | `POST /api/departures/{departure}/quote` and `/bookings`; `TourResource` drops its date/capacity fields, gains `upcoming_departures`, `next_departure`, `is_sold_out`; `BookingResource` gains `departure`; codes `departure_has_bookings`, `departure_started` | Nested `tours/{tour}/departures/{departure}/bookings` (a pair to check, nothing gained); keeping old fields filled from "the next departure" (misleading) |
| g. Catalogue | listed while an upcoming departure exists; "soonest" by `MIN(starts_on)` of upcoming departures; upcoming departures eager-loaded | A date-range filter: out of scope |
| Locks | `FOR SHARE` on the tour, then `FOR UPDATE` on the departure, everywhere in that order | `FOR UPDATE` on the tour: bookings of different dates would queue behind each other |
| h. Out of scope | per-departure prices, waitlists, cancelling a departure with refunds, moving a booked date, a catalogue date filter | |

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `2026_11_26_000100_create_departures_table.php` | table, cascade from tours, CHECKs, unique `(tour_id, starts_on)` and `(id, tour_id)` |
| Migration | `2026_11_26_000200_move_bookings_to_departures.php` | one departure per tour (`INSERT ... SELECT`), `bookings.departure_id` nullable → backfill → `NOT NULL`, composite FK, index `(departure_id, status)`; `down()` copies back, refuses with several departures |
| Migration | `2026_11_26_000300_drop_dates_and_capacity_from_tours.php` | `duration_days DROP EXPRESSION` + CHECK 1..30, old columns dropped; `down()` restores them, refuses unless exactly one departure per tour |
| Models | `Departure.php` (new), `Tour.php`, `Booking.php` | `Departure`: only `starts_on`/`capacity` fillable, `upcoming` scope, `available_spots`, `ends_on`, `hasStarted()`. `Tour`: `departures()` (with `chaperone()`), `upcomingDepartures()`, `upcoming` = has an upcoming departure, `orderByNextDeparture`; date/capacity attributes removed. `Booking`: `departure()`, cancellable until the departure starts |
| Factories | `DepartureFactory.php` (new), `TourFactory.php`, `BookingFactory.php` | `Departure::factory()->started()`; `Tour::factory()->withDeparture()`; `Booking` keeps `tour_id` and `departure_id` consistent for `for($tour)` and `for($departure)` |
| Requests | `StoreDepartureRequest`, `UpdateDepartureRequest` (new), `TourRequest` | ownership in `authorize()` (before the date check, so no dates leak); weekly series, "date already taken" errors; `TourRequest`: `duration_days` 1..30 instead of dates and capacity |
| Policy | `DeparturePolicy.php` (new) | delegates to `TourPolicy::update` on the departure's tour |
| Services | `BookingService`, `DepartureService` (new), `TourService` | booking locks tour (share) + departure, counts and releases spots on the departure; `DepartureService` create/update/delete under the departure lock; `TourService` drops the capacity rule |
| Errors | `BusinessRuleException` | `departureHasBookings()`, `departureStarted()`; messages say "departure" |
| Controllers | public `BookingController`, `TourController`, operator `DepartureController` (new), `TourController`, `DashboardController`, `BookingController`, admin `TourController`, `BookingController` | new booking/quote URLs; catalogue upcoming/sort/eager loads; dashboard lists departures with their tour |
| Resources | `DepartureResource` (new), `TourResource`, `BookingResource` | as in decision f |
| Routes | `routes/api.php` | traveler group: departure quote/booking; operator group: four departure endpoints |
| Other | `BookingConfirmedNotification`, `BookingsExport`, `DemoSeeder` | departure date in the e-mail and CSV; demo tours get 4 weekly departures |
| Types | `frontend/src/lib/types.ts` | `Departure`; `Tour` and `Booking` as the Resources |
| Hooks | `features/bookings/queries.ts`, `features/operator/queries.ts` | quote/booking per departure; `useTourDepartures`, `useAddDepartures`, `useUpdateDeparture`, `useDeleteDeparture` (invalidate operator and public tour keys) |
| Pages | `TourCard`, `TourDetailPage`, `BookingPage` (+ route `/tours/:id/departures/:departureId/book`), `MyBookingsPage`, `BookingsTable`, `TourFormPage`, `DeparturesSection` (new), `NextDeparture` (new), `OperatorToursPage`, `OperatorDashboardPage`, `AdminToursPage` | next date + "N more dates", sold out = `is_sold_out`; date list with a Book button per date; checkout for one departure; operator date management; new tours go to their edit page to get dates |
| Docs | `docs/02`, `04`, `05`, `06`, `07`, `08`, `10-exercises.md`, `decisions.md` (D23, D16), `AGENTS.md` §3, §8, §10, §11, this write-up + README row | |

## Tests

The suite went from 160 to **204** tests: 9 in the new
`DepartureDataModelTest`, 19 in the new `DepartureManagementTest`, and
BookingTest +6, DiscountTest +2, TourCatalogTest +4, TourManagementTest +4.

**Rewritten, not removed.** Existing booking, discount and expiry tests now
book a departure (`/api/departures/{id}/...`) and assert the departure's
counter; their rules are unchanged. Three tests moved with what they test:
`test_the_database_itself_refuses_overbooking` → `DepartureDataModelTest::test_the_database_refuses_overbooking_a_departure`;
TourManagement's `test_capacity_cannot_drop_below_the_spots_already_booked` →
the same name in `DepartureManagementTest`; the catalogue's sold-out and
spots-left tests → the per-departure versions below. `EmailVerificationTest`
uses the new booking URL.

**Red runs** (each run before the code it demands):

| Test(s) | Red before the change |
|---|---|
| `DepartureDataModelTest`: overbooking, negative counter, zero capacity, two departures on one day, tour delete cascades | first `Departure` class not found (weak), so the table was added **without** CHECKs, unique index and cascade: each failed with "no exception thrown" / FK violation, then passed with them |
| `the_migration_creates_one_departure_per_tour_and_links_every_booking`, `rolling_back_gives_dates_capacity_and_counters_back_to_the_tours`, `rolling_back_is_refused_while_a_tour_has_several_departures` / `..._no_departure` | migration files did not exist (`ErrorException` on require) |
| `BookingTest`: wallet, card, decline, cancel, overbook, **last spot for two travelers**, **spots counted per departure**, draft/past/today/suspended not bookable, **lock order**, cancel after start, old URL gone | 404 on the new URL (201 for the old-URL test) |
| `DiscountTest` + `ExpirePendingBookingsTest` (rewritten) | 404 on the new URLs |
| `the_database_refuses_a_booking_whose_tour_is_not_the_tour_of_its_departure` | **passed for the wrong reason** before the change (no `departure_id` column yet); it proves the composite FK since M2 |
| `booking_an_unknown_departure_is_not_found`, `unknown_tours_and_departures_are_not_found` | **passed for the wrong reason** (route missing → 404) |
| `DepartureManagementTest` (18 of 19) | 404: routes missing |
| `the_date_cannot_change_even_when_its_only_bookings_were_cancelled` (from the review) | 200 instead of 422 |
| `TourCatalogTest` (all rewritten/new) and `TourManagementTest` (duration, ignored dates, list, dashboard) | missing `withDeparture()`, old validation, missing fields |
| `the_database_refuses_a_tour_without_a_positive_length` | **passed for the wrong reason**: writing a generated column always fails |

## Verification

| Check | Result |
|---|---|
| Suite before the change | 160 passed |
| Full suite after | **204 passed** (801 assertions) |
| Pint | pass (168 files); three files were reformatted by Pint during the work (line endings from a Windows tool, import order) |
| Frontend build (`tsc -b` + vite) and lint (oxlint) | pass, 0 warnings, 0 errors |
| `DemoSeeder` | run on the **test** database (`migrate:fresh --seed`): 10 published tours × 4 weekly departures, draft without, 4 bookings, counters right |
| Migrations on the dev DB | applied (batch 5). By mistake: a `migrate --env=testing` meant for the test DB ran on dev (there is no `.env.testing`). Forward only, as the plan intended for verification; checked right after: 11 departures = the 11 tours' dates, capacities, counters and lengths; every booking linked; no tour mismatch |
| Rollback + re-migrate on dev (with the user's OK) | `migrate:rollback --step=3`: the 11 tours got back exactly their dates, capacities, counters and lengths, `duration_days` generated again, old CHECKs back, `departures` and `bookings.departure_id` gone, all 5 bookings intact. `migrate` again: tours/departures **identical** to the snapshot taken before (dates, end dates, capacity, counters, lengths); every booking on a departure of its own tour; 0 counters differing from their bookings; public API serves the 10 tours. Departure ids were renumbered (table recreated) |
| Queue worker | restarted (services and notification changed) |

Manual check on http://localhost:5173:

| Role | Step | Result |
|---|---|---|
| Guest | catalogue | 10 tours, sorted by next date, ranges and lengths right (Qadisha 24–26 Dec, 3 days), spots per date |
| Guest | tour page | "Choose a date", "17 of 20 spots left", "Log in to book" |
| Operator | create a tour | goes to its edit page with "Now add the dates it runs on" |
| Operator | add 4 weekly Saturdays from 28 Nov, group 6 | "4 dates added", listed 7 days apart |
| Operator | add the same series again | refused as a whole: "This tour already has a departure on 2026-11-28" |
| Operator | delete one unbooked date | deleted |
| Operator | dashboard, My tours | upcoming departures with "0 / 6 booked"; "28 Nov 2026 + 2 more dates" |
| Traveler | book 1 spot on Byblos 6 Dec (wallet) | checkout shows the date; booked; My bookings shows "6 Dec 2026" |
| Traveler | cancel it | refunded, spots back |
| Admin | tours, bookings | "Next date + N more"; bookings "Departs 6 Dec 2026" |
| All | console | only the expected guest 401s on `/api/user` and the deliberate 422 |

**Data restored:** the temporary tour (id 12) and its departures are deleted
(11 tours, 11 departures, as before); both travelers' balances are back
(`traveler@` $1,243.00, `traveler2@` $300.00); departure 8 is back to 3
booked spots. What stays, by design: the cancelled booking LT-MU6J264Q and
its two ledger rows (debit and refund, net $0). The ledger is append-only
(invariant 12), and the id sequences moved on. The browser was logged out.

**Not verified:** a truly parallel booking race (PHPUnit limits it; the
lock order and the CHECK are tested instead); the production stack.

## Follow-ups (not done here)

- Moving a booked departure to another date, with a notification to its travelers.
- Cancelling a whole departure with refunds.
- A date-range filter in the catalogue; a per-departure filter on the operator bookings page.
- Per-departure prices (e.g. high season).
- Blocking a length (`duration_days`) change while booked departures exist (today allowed, as `ends_on` changes were before).
