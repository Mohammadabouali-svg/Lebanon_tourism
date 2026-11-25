# Decision log

Every project is a pile of decisions. Writing them down, with the reason and
the alternatives that were rejected, saves the next developer (often future
you) from re-arguing them, or from "fixing" something that was deliberate.

Each entry follows the same shape: **context → decision → consequences**.
Professional teams call these *Architecture Decision Records* (ADRs).

---

## D1. Laravel rather than Symfony

**Context.** Both are excellent, mature PHP frameworks. The developer this
project is written for knows plain PHP and mysqli.

**Decision.** Laravel 13.

**Why.**
- The learning curve is gentler: conventions over configuration, one obvious
  place for each thing (routes, controllers, requests, policies, resources).
- The pieces the original project got wrong come built in and battle-tested:
  password hashing, signed URLs, password reset tokens, rate limiting, CSRF,
  validation, authorization policies, queues.
- Very large community and documentation: answers are easy to find.

**Consequences.** Laravel hides some machinery (facades, magic methods). When
something feels like magic, open the class: `vendor/` is on your disk on
purpose, and "Go to definition" works.

## D2. A JSON API plus a React single-page app, not server-rendered templates

**Decision.** API + React, but kept boring: one origin, cookie
sessions, no JWTs, no state-management library.

**Why.**
- The separation *forces* the lesson the original project needs most: the
  server cannot trust anything the browser sends. With the browser being a
  separate program, it is obvious that a hidden `base_price` field can be
  edited.
- It mirrors how most companies build web products today.
- The boundary (HTTP + JSON) is easy to test: every rule is covered by
  backend tests without a browser.

**Alternative.** Laravel + Blade (+ Livewire) would be fewer files and one
language. A perfectly good choice for this app; the security lessons are the
same.

## D3. PostgreSQL

**Decision.** Postgres 18.

**Why.** Strict by default (no silent truncation of values, a real `boolean`,
real `CHECK` constraints), generated columns, partial indexes, `ILIKE`. These
let the database enforce rules itself (see D6).

## D4. Session cookies (Laravel Sanctum "SPA mode"), not tokens in localStorage

**Decision.** The SPA authenticates with Laravel's normal session cookie
(HttpOnly, SameSite=Lax). Sanctum adds CSRF protection with the XSRF-TOKEN
cookie and header.

**Why.** A token in `localStorage` is readable by any JavaScript on the page,
so one XSS bug leaks every session. An HttpOnly cookie cannot be read by
JavaScript at all. Details in [03-authentication.md](03-authentication.md).

**Consequence.** The browser must reach the API on the same site as the SPA.
That is the next decision.

## D5. One origin: the browser only ever talks to one host

**Decision.** In development the Vite server proxies `/api`, `/sanctum` and
`/storage` to nginx. In production nginx serves the built SPA and proxies the
API. The browser sees one origin in both cases.

**Why.** No CORS configuration, no third-party-cookie issues, and dev behaves
like prod.

## D6. The database enforces the critical rules too

**Decision.** Besides PHP validation, the schema has `CHECK` constraints, foreign
keys with `RESTRICT`, unique and partial unique indexes. Examples: a wallet
balance can never be negative, a tour can never be overbooked, a booking's
totals must add up, one review per traveler per tour.

**Why.** Code has bugs, scripts bypass the code, two requests race each other.
The database is the last line of defence and the only one that sees every
write. See [04-database.md](04-database.md).

## D7. Money is an integer number of cents

**Decision.** `price_cents`, `total_cents`, `balance_cents`... as integers,
everywhere: database, PHP, JSON. The React app formats them for display.

**Why.** Floating point cannot represent most decimal fractions exactly
(`0.1 + 0.2 !== 0.3`). The original stored money as `double` and as
`decimal(10,0)` (which silently dropped the cents).

## D8. Business logic lives in service classes, controllers stay thin

**Decision.** `BookingService`, `WalletService`, `PricingService`,
`TourService` hold the rules. Controllers translate HTTP to a method call and
back.

**Why.** Rules become reusable (an admin action, a CLI command, a seeder) and
testable. The seeder in this project books tours through `BookingService`, so
even demo data obeys the rules.

**Alternative.** "Action" classes (one class per use case) are also popular.
Services were chosen because the booking rules share private helpers.

## D9. Authorization is declared on route groups and policies, never sprinkled

**Decision.** `routes/api.php` groups routes by audience (`role:admin`, ...).
Ownership checks ("is this *your* tour?") live in policies.

**Why.** In the original, every admin file had to remember its own check and
most forgot. With groups, a new endpoint is protected by the group it is
added to.

## D10. Users are suspended, not deleted

**Why.** Bookings, payments and ledger entries must stay traceable. Deleting
a user would orphan them or delete financial history.

## D11. Wallet = balance + append-only ledger, updated under a row lock

**Why.** The balance alone cannot answer "where did my money go?". The lock
(`SELECT ... FOR UPDATE`) prevents two simultaneous payments from both
spending the same money. See [05-bookings-and-payments.md](05-bookings-and-payments.md).

## D12. Card payments go through a `PaymentGateway` interface with a fake driver

**Why.** Real card handling (Stripe, ...) needs an account and keys, and card
numbers must never touch our server. The interface shows where a real
provider plugs in. The fake gateway declines everything in production, so a
misconfiguration cannot give tours away.

## D13. Uploaded images are re-encoded

**Why.** Validation alone trusts the file's claimed type. Re-encoding keeps
only the pixels: hidden payloads and GPS metadata are dropped, and the file
name and extension are ours. See [06-security.md](06-security.md).

## D14. Docker for everything, bind-mounted source in development

**Decision.** PHP, Composer, Node, Postgres and Mailpit all run in
containers. Your source folders are mounted into them.

**Why.** Same versions for everyone, whether they use Windows (WSL 2) or
macOS (Intel or Apple Silicon), nothing to install besides Docker and `make`,
and the production images are built from the same Dockerfiles. One
`make setup` works on both systems; the only per-machine values (user id,
file-watching mode) are detected by `make env`.

**Trade-off.** Bind-mounted folders are slower than the container's own file
system. It matters most for a project on a Windows drive reached from WSL
(`/mnt/c`, `/mnt/j`): the first requests after a change are slower and file
watching needs polling. Keeping the project in the WSL file system (Windows)
or in your home folder with VirtioFS or OrbStack (macOS) avoids most of it.
See [01-getting-started.md](01-getting-started.md#performance-notes).

**Alternative.** Named Docker volumes for `vendor/` and `node_modules/` are
faster, but those folders then disappear from your editor, and reading the
framework's source is part of learning it.

## D15. Tests run against Postgres, not SQLite

**Why.** Row locks, `CHECK` constraints, partial indexes and `ILIKE` behave
differently (or not at all) in SQLite. A test suite must exercise the database
that production uses.

## D16. What was deliberately left out

To keep the project teachable, some things are listed as exercises instead of
being built: e-mail address change with re-verification (since built in
Exercise 4, see D20), cleanup of stale
`pending` card bookings (since built in Exercise 5, see D18), a real Stripe
driver, recurring departures, full-text search, admin "lock" on moderated
tours (since built in Exercise 6, see D19). See [10-exercises.md](10-exercises.md).

## D17. The tour price cap is validation only, not a database constraint

**Context.** Exercise 2 caps tour prices at $10,000 (`max:1000000` cents in
`TourRequest`). Invariant 15 says critical rules are also database
constraints, so a `CHECK (price_cents <= 1000000)` was considered.

**Decision.** The cap lives only in `TourRequest`. The existing
`tours_price_positive CHECK (price_cents > 0)` stays the database's guard.

**Why.** Constraints protect *integrity*: a negative price or an overbooked
tour is corrupt data, whatever the product wants. A price of $12,000 is not
corrupt, it is just above today's business policy, and policies change. Every
change of a CHECK needs a migration, and adding one to a live table fails
outright if any existing row breaks it. The only way to write a tour price is
the operator create/update endpoint, which always runs `TourRequest`, so a
hostile client (curl) cannot get round the rule.

**Consequences.** Server code (seeders, tinker, a future import) could store
a higher price. A tour created before the cap with a higher price can still
be read and booked, but must be lowered to $10,000 before the operator can
save any edit to it.

**Rejected.** A `CHECK` constraint (couples a product limit to the schema;
would need `NOT VALID` or a data clean-up on a real database). Enforcing the
limit in `TourService` (it is a plain input bound, which is the Form
Request's job, and the 422 field error is what the form displays).

## D18. Stale pending card bookings expire after 15 minutes

**Context.** A card booking holds its spots as `pending` while the card is
charged outside any transaction (chapter 05, "Booking with a card"). If the server crashes
between the charge and the confirmation, the booking is never confirmed or
released. Exercise 5 asks for a scheduled command that marks such bookings
`failed` and releases their spots.

**Decision.**
- `bookings:expire-pending` runs **every minute** with `withoutOverlapping()`
  and calls a new public `BookingService::expireStalePending()`. The timeout is
  `config('payments.pending_timeout_minutes')` (15), next to the gateway
  configuration it must stay above.
- `releaseSpots()` stays **private**. The public method locks each booking and
  re-checks that it is still `pending` and older than the cutoff before
  releasing, one short transaction per booking (the `cancel()` pattern).
- `markPaid()` also locks the booking and confirms it **only if it is still
  pending**. A charge that succeeds after expiry leaves the booking `failed`,
  credits the amount to the wallet as travel credit, and returns the new code
  `booking_expired`.
- A booking expired after a crash is **not refunded** automatically; it is
  logged for manual reconciliation. No e-mail is sent.
- **No new index** on `(status, created_at)`.

**Why.** Making `releaseSpots()` public would let a caller release the spots of
a booking that is already cancelled or failed, counting them back twice. Without
the check in `markPaid()`, a slow charge could confirm a booking whose spots
were already given to someone else: overbooking (proven by
`test_a_charge_that_succeeds_after_expiry_does_not_confirm_the_booking`, which
got 201 before the fix). In the late-charge case we *know* the card was
charged, so the traveler is made whole, consistent with the cancellation
policy. In the crash case we do not know, and the fake gateway cannot be
asked, so an automatic credit could hand out money for an unpaid booking. One
transaction per booking keeps locks short and isolates failures. Every minute
bounds how long spots are held (about 16 minutes); the query is cheap at this
project's size, since few rows are ever pending.

**Consequences.** A traveler whose card was charged just before a crash ends
up with a `failed` booking until someone reconciles it by hand; a real
provider's webhook (Exercise 9) should settle that case. Travelers are not
notified of an expiry. The scheduler only runs in the production compose file,
so in development the command must be run by hand. If the bookings table grows
large, a partial index `ON bookings (created_at) WHERE status = 'pending'`
would make the query constant-time (see Exercise 11).

**Rejected.** Making `releaseSpots()` public as it is (no re-check). One
transaction for all stale bookings (long locks, one failure rolls back all).
Crediting the wallet on every expiry (free money when the card was never
charged). An e-mail to the traveler (in the crash case we cannot say whether
they paid). Running every five minutes (spots held up to 20 minutes for no
real saving).

## D19. The admin moderation lock is a service rule, not a policy or Form Request

**Context.** An operator could re-publish a tour an admin had archived.
Exercise 6 adds `moderated_at`: while it is set, the operator cannot change
the tour's status. The exercise asks where that rule belongs: Form Request,
policy or service. Two facts shape the answer. Every operator save sends a
`status` (`TourRequest` requires it), so the rule must compare the requested
status with the stored one. And the operator remains the tour's owner, who
may still fix its other fields.

**Decision.**
- The rule lives in **`TourService`**. `save()` already locks the tour row to
  check `booked_spots`; it now reads `status` and `moderated_at` from the
  locked row too, and throws `BusinessRuleException::tourModerated()` (422,
  code `tour_moderated`) when a locked tour's status would change. Sending the
  current status again is allowed, and so are edits to all other fields.
- The admin side moves into **`TourService::moderate()`**: one transaction,
  the same row lock, `forceFill` for the non-fillable `moderated_at`.
  Archiving sets the lock (keeping the original time if it was already set);
  any other status reinstates the tour and clears it. There is no separate
  unlock endpoint, and an admin publishing does not lock.
- A **`CHECK (moderated_at IS NULL OR status = 'archived')`**
  (`tours_moderated_means_archived`) states what the lock means.
- `TourResource` exposes `moderated_at`; the operator's form disables the
  status select and shows the reason.

**Why.** A **Form Request** would read the stored tour *before* the
transaction. An admin archiving between validation and save would then not be
seen (a time-of-check/time-of-use race). It would also put a business rule
into the validation layer (§5). A **policy** answers "may this user act on this
record?". The honest answer here is yes: the operator may edit their tour.
Refusing `update()` would block every edit of a locked tour. A separate
`changeStatus` ability would need the controller to compare the input with the
stored status, which is business logic outside the service. Either way the
answer would be a 403 "This action is unauthorized", which misleads the
operator. In the **service** the check runs under the row lock, so admin and
operator requests on the same tour are serialised (invariant 13). The error
has a stable code (invariant 22) that the form maps to the status field. Any
future caller (an import, a command) gets the rule for free. All three options
run on the server, so none of them can be got round with curl; the
differences are the race, the meaning of the error and the layer.
The CHECK is integrity, not a tunable limit like D17's price cap: a locked
*and* published tour would be contradictory data.

**Consequences.** Every published tour has `moderated_at` NULL, so the
field can be shown publicly without revealing anything. An admin who wants to
lift the lock but leave the tour hidden sets it to `draft`. An operator may
still delete a locked tour that has no bookings (unchanged; a possible
follow-up). If moderation ever needs a lock on non-archived tours (e.g. "locked
as published"), the CHECK must be replaced in a new migration.

**Rejected.** A Form Request `after()` rule (race, wrong layer). A
`TourPolicy` ability (403 with the wrong meaning, blocks other edits, input
logic in a policy). Locking on every admin status change plus an unlock
endpoint (a new route and button for a case the exercise does not need). A
lock independent of the status (most flexible, most to build, and no
constraint could state its meaning).

## D20. E-mail change: a pending column, verified before the swap

**Context.** Exercise 4 lets users change their e-mail address. Changing it
directly would be dangerous: a typo locks the user out (login, password
reset and every notification use the address), and an attacker holding a
stolen session could move the account to their own inbox and then reset the
password. `email` is fillable (sign-up needs it), so adding it to
`UpdateProfileRequest` would have changed it instantly.

**Decision.**
- **One nullable `users.pending_email` column**, not fillable, lower-cased by
  a mutator, written only by `EmailChangeService`. A new request overwrites
  it, which also invalidates every older link. A **CHECK
  `users_pending_email_differs`** states that a pending address differs from
  the current one. There is **no unique index** on `pending_email`.
- **Requesting** (`POST /api/user/email-change`) requires the **current
  password** (as for a password change) and is throttled to 6 per minute. The
  Form Request refuses the current address and a taken one with ordinary 422
  field errors.
- **The link** reuses the verification design: a relative temporary signed
  route (60 minutes), the hash of the *pending* address, a React page that
  calls `POST /api/email/change/{id}/{hash}`, no login needed.
- **The swap** (`EmailChangeService::confirm()`) runs in one transaction under
  a row lock: the hash must match the address pending now (403 otherwise),
  the address must still be free (`BusinessRuleException::emailTaken()`,
  code `email_taken`, with the unique index on `email` as the backstop), then
  `email` = new, `pending_email` = null, `email_verified_at` = now, and the
  old address's password reset tokens are deleted. After the commit, the
  **old address is notified**.
- Sessions are left alone; `DELETE /api/user/email-change` cancels.

**Why.** Verifying before swapping is the exercise's point: the account keeps
a working address at every moment. The password re-check means a stolen
session is not enough; the notice to the old address warns the owner if the
password was stolen too. A unique index on `pending_email` would let anyone
*block* an address by requesting it first, so uniqueness is enforced where it
matters: on `email`, at the swap. Refusing a taken address at request time
leaks nothing new (the public sign-up form already answers "taken") and is
behind login, password and a rate limit. The reset-token cleanup closes a
real hole: the tokens are keyed by e-mail, so a token for the old address
would otherwise reset the password of whoever signs up with that address
next. The rules are in a service (§5) because they need the row lock and the
re-check at swap time; the Form Request only shapes input.

**Consequences.** `UserResource` gains `pending_email` (additive, backward
compatible). A user who requests A, cancels, and requests A again within the
hour makes the first link valid again. It only ever reaches the same inbox,
for the same address, so this is accepted. The old-address notice has no
"undo" link; a user who did not make the change must contact support. A
suspended user's link still works if they were suspended after requesting
(the request itself needs `active`); a possible follow-up.

**Rejected.** An `email_changes` table (history and several open requests:
nothing needs them; Exercise 12's audit log is the place for history).
Swapping immediately and verifying afterwards (the typo/lock-out problem the
exercise describes). Notifying the old address at request time (earlier, but
without a cancel link in that e-mail it cannot stop anything). Ending all
sessions at the swap (would log out the real owner as easily as an attacker,
and the password did not change). Removing `email` from `#[Fillable]` (sign-up
and admin creation set it legitimately; it would touch code outside this
exercise. A possible separate clean-up).

## D21. Review photos: a capped child table, cleaned up by the service after commit

**Context.** Exercise 8 allows up to three photos per review. Reviews already
had one optional photo in `reviews.photo_path`, written by the controller.
Files are not part of database transactions, and three 5 MB photos did not fit
in the 10 MB request limit (PHP `post_max_size`, nginx `client_max_body_size`).

**Decision.**
- **A `review_photos` child table** (`review_id`, `position`, `path`), FK
  `ON DELETE CASCADE` (photos are owned by their review). "At most three" is a
  database fact without a trigger: CHECK `review_photos_position_range`
  (`position BETWEEN 1 AND 3`) plus a unique index on `(review_id, position)`.
- **`photo_path` is migrated and dropped** in a separate migration
  (`INSERT ... SELECT` as position 1, then `DROP COLUMN`). `down()` copies
  position 1 back; photos 2 and 3 are lost on rollback (files stay on disk).
- **API:** `photo_url` is replaced by `photos: [{id, url}]` (upload order); the
  request field `photo` is replaced by `photos[]` (`array|max:3`, each
  `image|mimes:jpg,jpeg,png,webp|max:5120`). No transition period: the React
  app is the only client and ships with the API.
- **Size:** 5 MB per photo is kept; the request limit is raised to 20 MB
  (`post_max_size = 20M`, `client_max_body_size 20m` in dev and prod).
- **`ReviewService`** (`create`, `delete`) owns the file handling: photos are
  stored first (outside any transaction), the review and its photo rows are
  inserted in one transaction, and on *any* failure (a photo that GD cannot
  decode, the unique index refusing a duplicate review) every file already
  written is deleted again. On delete, the paths are read, the review is
  deleted (rows cascade), and the files are deleted **after the commit**.

**Why.** A child table makes "how many" and "in what order" plain data, and
the CHECK + unique index enforces the cap even under a race or a future code
path that forgets validation, at almost no cost (unlike D17's price cap, the
limit is part of the storage shape, not a business number that may change).
Keeping one source of truth avoids every reader having to merge two places.
Phone photos are often 3-6 MB, so lowering the per-photo limit would have
refused ordinary uploads; the bigger body is bounded by `throttle:uploads`
(10/min) and the verified-traveler route group, and ImageUploader shrinks
every photo to 1600 px. The service follows `TourService::save()`: the
controller stays thin, and the cleanup sits next to the code that created the
files.

**Consequences.** The database cascades (review → photos, and tour/user →
reviews) never touch files. Today the tour/user cascades cannot fire with
reviews present: users are never deleted (invariant 21), and
`TourService::delete()` refuses a tour with bookings, which every reviewed
tour has (`bookings.tour_id` is also `restrictOnDelete`). A future "delete
user/tour" feature must delete review photo files itself. A request above
20 MB gets a 413 from nginx/PHP without a field error (the UI shows a generic
message). Photos can only be added when the review is written.

**Rejected.** `photo_1..3` columns (order and count become code, not data). A
trigger counting rows (more to explain, and racy without a lock). Validation
only (cheap to enforce in the schema, so no reason not to). Keeping
`photo_path` alongside (two sources of truth). Accepting both `photo` and
`photos[]` (a code path for a client that does not exist). Lowering photos to
3 MB (refuses ordinary phone photos). Deleting files in a model `deleting`
event (runs before the commit, so a rollback would leave rows pointing to
deleted files, and hides a side effect in the model).
