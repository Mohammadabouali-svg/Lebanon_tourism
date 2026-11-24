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
being built: e-mail address change with re-verification, cleanup of stale
`pending` card bookings, a real Stripe driver, recurring departures, full-text
search, admin "lock" on moderated tours. See [10-exercises.md](10-exercises.md).

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
