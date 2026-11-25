# 10. Exercises

Features that were left out on purpose, roughly from easiest to hardest. Each
one practises something from the manual. For every exercise: write the test
first (or at least alongside), and update the comments and docs you touch.

## Warm-ups

**1. Meeting point.** Add an optional `meeting_point` text to tours. You will
touch: a new migration, `Tour` fillable, `TourRequest`, `TourResource`, the
frontend type and form, the detail page, and a test. Good for learning the
path of a field through every layer ([chapter 04](04-database.md#changing-the-schema)).
*Implemented:* see migration `2026_11_10_000100_add_meeting_point_to_tours`
and the `meeting_point` tests in `TourManagementTest` and `TourCatalogTest`.
Write-up: [exercises/01-meeting-point.md](exercises/01-meeting-point.md).

**2. Price limit.** Operators may not set a price above $10,000. Write the
failing test first (the test is already sketched in
[chapter 08](08-testing.md#writing-a-test-for-a-new-feature)).
*Implemented:* `max:1000000` and a readable message in `TourRequest`, a hint
on the operator form, the price tests in `TourManagementTest`, and
[D17](decisions.md#d17-the-tour-price-cap-is-validation-only-not-a-database-constraint)
for why there is no database constraint. Write-up:
[exercises/02-price-limit.md](exercises/02-price-limit.md).

**3. "Sold out" badge.** The API already returns `available_spots` and
`is_bookable`. Show a badge on tour cards and hide the book button. Pure
frontend.
*Implemented:* a "Sold out" `Badge` over the image in `TourCard.tsx` (shown
when `available_spots === 0`, image muted); the detail and booking pages
already hid the book button on `!is_bookable`. Two tests in
`TourCatalogTest` pin the API behaviour the badge relies on. Write-up:
[exercises/03-sold-out-badge.md](exercises/03-sold-out-badge.md).

## Intermediate

**4. Change e-mail address.** Let users change their e-mail. The new address
must be verified before it replaces the old one (otherwise a typo locks the
user out, and an attacker with a stolen session could take the account over).
Hint: store `pending_email`, send a signed link to it, swap on click.

**5. Expire stale pending bookings.** If the server crashes between charging
a card and confirming the booking, a `pending` booking keeps its spots
forever. Write an artisan command that marks pending bookings older than 15
minutes as `failed` and releases their spots (reuse `BookingService`; you
will need to make the release logic public). Schedule it in
`routes/console.php`. Test it with `$this->travel(20)->minutes()`.
*Implemented:* `bookings:expire-pending` (every minute) calling
`BookingService::expireStalePending()`, which locks and re-checks each booking;
`markPaid()` no longer confirms an expired booking. Tests in
`ExpirePendingBookingsTest`. Write-up:
[exercises/05-expire-pending-bookings.md](exercises/05-expire-pending-bookings.md).

**6. Admin moderation lock.** Today an operator can re-publish a tour an
admin archived. Add a `moderated_at` column; while it is set, the operator
cannot change the status. Where does this rule belong: Form Request, policy
or service? (Discuss the trade-offs before choosing.)
*Implemented:* migration `2026_11_25_000100_add_moderated_at_to_tours` (with
the `tours_moderated_means_archived` CHECK); an admin archive sets the lock and
any other admin status clears it (`TourService::moderate()`); the operator's
status change is refused in `TourService` with the code `tour_moderated`
(the service option, D19). Tests in `TourManagementTest` and
`AdministrationTest`. Write-up:
[exercises/06-moderation-lock.md](exercises/06-moderation-lock.md).

**7. Content-Security-Policy.** Add a strict `Content-Security-Policy` header
in `docker/nginx/prod.conf` (`default-src 'self'; img-src 'self' data:; ...`).
Check the browser console for anything it blocks. CSP is a second line of
defence against XSS.

**8. Review photos gallery.** Allow up to three photos per review. You need a
`review_photos` table, array validation (`photos.*`), and cleanup of the
files when a review is deleted.

## Advanced

**9. A real payment provider.** Implement `StripePaymentGateway` against
Stripe's test mode. Use Stripe Checkout (Stripe hosts the card form), confirm
bookings from Stripe's webhook rather than from the browser redirect, and
verify the webhook signature. Bind it in `AppServiceProvider` when
`PAYMENT_GATEWAY=stripe`.

**10. Recurring departures.** Today a tour has exactly one date. Real tour
operators run the same tour every Saturday. Introduce a `departures` table
(tour, date, capacity, booked_spots) and move booking and capacity there.
This is a real data-model refactoring: plan the migration of existing data
before writing code.

**11. Faster search.** `ILIKE '%term%'` cannot use a normal index, so it
gets slow with many tours. Try Postgres' `pg_trgm` extension with a GIN
index, or full-text search (`to_tsvector`). Measure before and after with
`EXPLAIN ANALYZE` on 100,000 generated tours.

**12. Audit log.** Record who changed what in the admin area (approvals,
suspensions, wallet credits, tour status) in an `audit_events` table, and
show it on a new admin page.

## Reading exercises

- Open `vendor/laravel/framework/src/Illuminate/Auth/Passwords/DatabaseTokenRepository.php`
  and find where the reset token is generated, hashed and checked for expiry.
- Find where Sanctum decides whether a request is "stateful":
  `vendor/laravel/sanctum/src/Http/Middleware/EnsureFrontendRequestsAreStateful.php`.
- Run `make artisan c="route:list --path=api -v"` and check every route's
  middleware against [chapter 03](03-authentication.md).
