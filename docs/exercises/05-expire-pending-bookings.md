# Exercise 5: Expire stale pending bookings

## What was asked

> If the server crashes between charging a card and confirming the booking,
> a `pending` booking keeps its spots forever. Write an artisan command that
> marks pending bookings older than 15 minutes as `failed` and releases
> their spots (reuse `BookingService`; you will need to make the release
> logic public). Schedule it in `routes/console.php`. Test it with
> `$this->travel(20)->minutes()`.
> ([10-exercises.md](../10-exercises.md))

## What we found first

| Fact | Where |
|---|---|
| Card flow: transaction 1 holds the spots with a `pending` booking → charge with no transaction → `markPaid()` or `releaseSpots(Failed)` | `BookingService::bookWithCard()` |
| `releaseSpots()` is private; it locks the **tour**, gives back spots and the discount use, sets the status. It does **not** lock or re-check the booking | `BookingService` |
| `markPaid()` saved the booking it already had in memory as `confirmed`: no lock, no status check | `BookingService` |
| `cancel()` is the safe pattern: lock the booking, re-check, then release | `BookingService` |
| `failed` already exists (enum + `bookings_status_valid`); `bookings_one_discount_per_user` only counts pending/confirmed, so a failed booking frees the code | enum, migration |
| No command but `CreateAdmin`; the scheduler only runs in `compose.prod.yaml` | `app/Console/Commands`, compose files |
| The frontend already knows `failed` (type, red badge, admin/operator filters) | `lib/types.ts`, `Badge.tsx` |
| `PaymentGateway` only has `charge()`: no refund, no way to ask "was this paid?" | `app/Payments` |

**A real race** came out of this: if a charge takes longer than the timeout,
the command expires the booking and gives its spots away, then `markPaid()`
writes `confirmed` over `failed`. The tour is overbooked, and if the traveler
reused the discount meanwhile, the unique index breaks *after* the card was
charged. The test for it got **201** before the fix (see Tests).

## Decisions

| Question | Choice | Why |
|---|---|---|
| Public API | New public `BookingService::expireStalePending(): int`; `releaseSpots()` **stays private** | A public `releaseSpots()` would let a caller release a cancelled/failed booking's spots a second time. The new method locks each booking and re-checks it is still `pending` and older than the cutoff, like `cancel()`. |
| Where "15 minutes" lives | `config('payments.pending_timeout_minutes')` | Next to the gateway it must stay above. The command stays a thin trigger. |
| The race | `markPaid()` also locks the booking and confirms only if still `pending` | Locking only the expire side would not stop `markPaid()` from overwriting `failed`. |
| Charge succeeds after expiry (user's choice) | Booking stays `failed`, amount **credited to the wallet**, error code `booking_expired` | Here we *know* the card was charged; same travel-credit policy as cancellation. |
| Expired after a crash (user's choice) | Mark `failed`, **log a warning** with the reference, no automatic refund | We cannot know whether the card was charged; a credit could give money for an unpaid booking. A real provider's webhook (Exercise 9) settles it. |
| Transactions | One short transaction per booking, candidates read with `chunkById(100)` | Short locks; one bad row cannot roll back others; `chunkById` stays correct while rows change status. |
| Run twice / at once | Idempotent by the lock + re-check; `withoutOverlapping()` on the schedule as a belt-and-braces | A second run finds nothing pending. |
| Notify the traveler | No | In the crash case we cannot say whether they paid. Follow-up. |
| Frequency | Every minute | Spots held at most ~16 min; the query is cheap. |
| Index on `(status, created_at)` | No | Few rows are ever pending at this size. Partial index noted for Exercise 11. |
| ADR | **D18** in `docs/decisions.md`; D16 updated | Several deliberate design choices and rejected alternatives. |

## What was done

| Layer | File | Change |
|---|---|---|
| Config | `backend/config/payments.php` | `pending_timeout_minutes => 15`, with a *why* comment |
| Service | `backend/app/Services/BookingService.php` | public `expireStalePending()` + private `expireIfStale()` (lock, re-check, `releaseSpots(Failed)`, log); `markPaid()` locks and re-checks, credits the wallet and throws after commit if expired |
| Exception | `backend/app/Exceptions/BusinessRuleException.php` | `bookingExpired()` → code `booking_expired` |
| Command | `backend/app/Console/Commands/ExpirePendingBookings.php` | `bookings:expire-pending`, prints "Expired N stale pending booking(s)." |
| Schedule | `backend/routes/console.php` | `everyMinute()->withoutOverlapping()` |
| Enum comment | `backend/app/Enums/BookingStatus.php` | Lifecycle diagram includes the expiry path |
| Tests | `backend/tests/Feature/Booking/ExpirePendingBookingsTest.php` | 7 tests (below) |
| Docs | `docs/05-bookings-and-payments.md`, `docs/10-exercises.md`, `docs/decisions.md` (D16, D18), `AGENTS.md` §10 + §11, this write-up + README row | "Known gap" replaced by "Stale pending bookings" |

No migration, model, request, Resource, route, TypeScript type or page
changed. The booking page already shows any booking error's `message`, so a
traveler hitting `booking_expired` sees the wallet-credit explanation.

## Tests

A stuck booking is made the honest way: the tests book through
`BookingService` with a gateway whose `charge()` throws (a simulated crash),
so the booking really is `pending` with spots and discount use held.

| Test | Proves |
|---|---|
| `test_a_pending_booking_older_than_15_minutes_is_marked_failed_and_its_spots_released` | 20 min later: `failed`, no `paid_at`, `booked_spots` back to 0, output says 1 |
| `test_expiring_a_booking_releases_its_discount_use_so_the_code_can_be_used_again` | `redemptions_count` back to 0, and the same traveler can book with the code again |
| `test_a_pending_booking_younger_than_15_minutes_is_left_alone` | 10 min: still `pending`, spots still held |
| `test_confirmed_cancelled_and_failed_bookings_are_left_alone` | a day later, other statuses and `booked_spots` unchanged |
| `test_running_the_command_twice_releases_the_spots_only_once` | second run expires 0; another booking's spot is not taken away |
| `test_a_charge_that_succeeds_after_expiry_does_not_confirm_the_booking` | 422 `booking_expired`, booking `failed`, spots stay 0, wallet credited with a ledger row pointing at the booking, no notification |
| `test_the_command_is_scheduled_every_minute_without_overlapping` | `* * * * *` and `withoutOverlapping` on the scheduled event |

### Red run

1. Before any code: **7 failed**. Six with
   `The command "bookings:expire-pending" does not exist.` (the late-charge
   one as `Failed asserting that 500 is identical to 422`), and the schedule
   test with `bookings:expire-pending is not scheduled.`
2. After the command and `expireStalePending()`, but **before** changing
   `markPaid()`: 6 passed, and the late-charge test failed with
   `Failed asserting that 201 is identical to 422.` That is the race proven:
   the old `markPaid()` confirmed a booking whose spots were already released.
3. After the `markPaid()` fix: **7 passed (37 assertions)**.

## Verification (2026-11-25)

| Check | Result |
|---|---|
| Full backend suite before the change | 95 passed (311 assertions) |
| Full backend suite after | 102 passed (348 assertions) |
| `ExpirePendingBookingsTest` | 7 passed (37 assertions) |
| Pint `--test` | pass (141 files) |
| Frontend build (`tsc -b` + vite) | pass (untouched) |
| Frontend lint (oxlint) | 0 warnings, 0 errors |
| `schedule:list` | `* * * * * php artisan bookings:expire-pending` |
| Manual, dev DB, fresh stuck booking | `Expired 0`, booking untouched |
| Manual, after `created_at` moved back 20 min | `Expired 1`, then `Expired 0` on a second run; booking `failed`; tour spots and discount use back; wallet unchanged; warning in `laravel.log` |
| Manual, `traveler@`, "My bookings" | LT-4XSMQ4KA shown as **Failed**, no "Cancel booking" button |

Dev data used for the manual check, then restored:

| Item | Original | During | After command | Restored |
|---|---|---|---|---|
| Tour 2 "Jeita Grotto" `booked_spots` | 0 | 2 | 0 | 0 ✔ |
| Discount SUMMER25 `redemptions_count` | 1 | 2 | 1 | 1 ✔ |
| `traveler@` wallet | 124,300 cents, 3 ledger rows | unchanged | unchanged | unchanged ✔ |
| Booking LT-4XSMQ4KA | (did not exist) | `pending` | `failed` | row deleted ✔ |

The stuck booking was created in tinker through `BookingService` with a
crashing gateway, and only its `created_at` was moved back in SQL (a separate
process cannot use `travel()`). The command itself restored the counters.

Not verified: two command runs truly in parallel (the lock + re-check is the
guarantee; the sequential double run is tested), the scheduler actually firing
(dev has none; the schedule entry is tested and listed), and the late-charge
path in the browser (it needs a gateway slower than 15 minutes; covered by its
feature test).

## Things to know

- Follow-ups: notify the traveler on expiry once a real gateway can tell us
  whether they paid; Exercise 9's webhook should reconcile bookings expired
  after a crash; a partial index if the bookings table grows (Exercise 11).
- In development the scheduler does not run: use
  `docker compose exec php php artisan bookings:expire-pending`.
