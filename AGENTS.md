# AGENTS.md — Lebanon Tourism

Project memory and working rules for AI coding agents (Claude Code, Codex,
Cursor, Copilot, ...) and for humans changing this codebase. Read it fully
before the first edit of a session. **This file is the single source of
truth.** `CLAUDE.md` only imports it (`@AGENTS.md`), so a reference such as
"CLAUDE.md §4" in a skill or doc means §4 of this file. When a fact here
goes stale, fix this file in the same change.

Sections 1–12 describe the project and how to change it. Sections 13–16
describe how to work with the user, the local machine, other agents and git
hosting. Section numbers are cited by `.claude/skills/`: add new sections at
the end instead of renumbering.

---

## 1. What this project is

A tour booking platform for Lebanon with three roles:

- **Travelers** browse, book (wallet or card), cancel and review tours.
- **Operators** (companies, approved by an admin) publish tours and see their bookings.
- **Admins** run the platform: users, operator approvals, tour moderation,
  bookings, discounts, catalogue (regions, cities, categories), wallet credits.

It is a from-scratch rewrite of an insecure student project, and it is a
**teaching codebase**: it is meant to be *read*. Comments explain *why*, and
`docs/` is a manual. Every change must keep it that way: readable, commented
where a decision was made, documented, tested.

| Layer    | Technology |
|----------|------------|
| API      | Laravel 13 (PHP 8.4), Sanctum SPA session auth |
| Database | PostgreSQL 18 (also sessions, cache and job queue) |
| Frontend | React 19, TypeScript (strict), Vite, Tailwind CSS 4, React Router 8, TanStack Query 5, axios |
| Runtime  | Docker Compose: nginx, PHP-FPM, queue worker, Postgres, Mailpit, Vite |

No UI kit, no form library, no chart library, no state-management library.
**Adding a dependency is a decision**: justify it and record it in
`docs/decisions.md`, or don't add it.

## 2. Environment and commands

Everything runs in Docker. Nothing (PHP, Composer, Node) is installed on the
host. Commands are `make` targets run from the project root in a **WSL
terminal** (Windows) or Terminal (macOS), **not PowerShell**.

This checkout lives at `C:\Users\mmm\OneDrive\Desktop\lebanon-tourism123`
(a OneDrive folder on Windows). From WSL that is `/mnt/c/...`: expect slow
bind mounts and Vite polling (`VITE_USE_POLLING=true`, set by `make env`). If
Docker behaves strangely, OneDrive sync is a suspect; moving the project into
the WSL file system is the fix (docs/01-getting-started.md).

```bash
make setup                      # first run: env, images, deps, migrate, seed
make up / make down / make logs
make test                       # backend test suite (Postgres test DB)
make test-filter f=BookingTest  # one class or name fragment
make pint                       # format PHP (run before every commit)
make npm c="run build"          # tsc -b type check + vite build
make npm c="run lint"           # oxlint
make migrate                    # run new migrations
make fresh                      # wipe DB, re-migrate, re-seed demo data
make artisan c="route:list --path=api"
make artisan c="make:migration add_x_to_y"
```

Dev containers (`compose.yaml`): `php`, `queue`, `nginx`, `frontend`, `db`,
`mailpit`. There is **no scheduler in dev**; `compose.prod.yaml` adds one
(`schedule:work`). The test DB `lebanon_tourism_test` is created by
`docker/postgres/init/01-create-test-database.sh` on first volume creation.

URLs: app http://localhost:5173 · Mailpit http://localhost:8025 · API
http://localhost:8080/api · Postgres `localhost:54320`.
Demo accounts (password `password`): `traveler@`, `traveler2@`,
`unverified@`, `operator@`, `operator2@`, `pending@`, `admin@`
`lebanon-tourism.test`. Discount codes: `WELCOME10`, `SUMMER25`, `EXPIRED5`.

**No `make` on the machine** (e.g. Windows without WSL)? Use the plain
`docker compose` equivalents in §14. The API port can be moved with
`API_PORT` in the root `.env` when 8080 is taken; the API is then on that port.

**If you (an AI assistant) cannot run these commands from your shell**, say
so explicitly and give the user the exact commands to run. Never claim tests
pass, a build succeeds or a migration works without having seen the output.

## 3. Architecture

```
Browser ──► Vite :5173 (dev) / nginx (prod) ──proxy /api,/sanctum,/storage──► nginx ──► PHP-FPM (Laravel) ──► Postgres
                                                                                      queue worker ──► Mailpit/SMTP
```

One origin in dev and prod: no CORS, cookies just work. Never introduce a
second origin or CORS config.

### Backend map (`backend/`)

| Path | Responsibility |
|---|---|
| `routes/api.php` | **The security map.** Every endpoint, grouped by audience. Access rules live on the groups. |
| `app/Http/Middleware/` | `active` (not suspended), `role:x`, `operator.approved` |
| `app/Http/Requests/` | Input validation (Form Requests) |
| `app/Http/Controllers/Api/{,Auth,Operator,Admin}/` | Thin HTTP layer, one folder per audience |
| `app/Http/Resources/` | Output shaping. Models are never returned directly. |
| `app/Policies/` | Record-level authorization (`TourPolicy`, `BookingPolicy`, `ReviewPolicy`) |
| `app/Services/` | Business rules: `BookingService`, `PricingService` (+ `Quote`), `WalletService`, `TourService` |
| `app/Payments/` | `PaymentGateway` interface, `ChargeResult`, `FakePaymentGateway` (approves in dev, declines in prod) |
| `app/Queries/` | Reporting queries (`BookingStats`) — aggregate in SQL |
| `app/Exports/`, `app/Support/CsvExport.php` | CSV with formula-injection protection |
| `app/Support/` | `ImageUploader` (re-encode to WebP), `Money`, `Search` (LIKE escaping) |
| `app/Enums/` | `UserRole`, `TourStatus`, `BookingStatus`, `OperatorStatus`, `PaymentMethod`, `DiscountType`, `WalletTransactionType` |
| `app/Exceptions/BusinessRuleException.php` | 422 + stable `code`; named constructors per rule |
| `app/Notifications/` | Queued e-mails |
| `app/Providers/AppServiceProvider.php` | Gateway binding, strict models, immutable dates, morph map, password rules, named rate limiters |
| `database/migrations/` | Schema source of truth, incl. `CHECK` constraints via `DB::statement` |
| `database/factories/`, `seeders/` | Test data; `DemoSeeder` books through `BookingService` |
| `routes/console.php` | Scheduler (`schedule:work` in prod) |
| `tests/Feature/`, `tests/Unit/` | HTTP tests against real Postgres; pure unit tests |

### One request, layer by layer

`POST /api/tours/12/bookings` → nginx → global middleware → Sanctum stateful
(session + XSRF, 419) → route group middleware `auth:sanctum` (401) →
`active` (403) → `verified` (403) → `role:traveler` (403) → `throttle:bookings`
(429) → route model binding (404, runs *after* role checks) → Form Request
(422 field errors) → thin controller → service (transaction + row locks,
`BusinessRuleException` 422 with `code`) → Resource → queued notification.

### Response shapes (the frontend depends on them — do not change)

```jsonc
{ "data": { ... } }                                        // single item
{ "data": [ ... ], "links": { ... }, "meta": { ... } }     // paginated list
{ "message": "...", "errors": { "field": ["..."] } }       // 422 validation
{ "message": "...", "code": "not_enough_spots" }           // 422 business rule
{ "message": "..." }                                       // 401/403/404/419/429/500
```

### Frontend map (`frontend/src/`)

| Path | Responsibility |
|---|---|
| `main.tsx`, `router.tsx` | Providers; every URL, layouts, guards; operator/admin routes are `lazy` |
| `lib/api.ts` | axios instance (`/api`, credentials, XSRF), `ensureCsrfCookie()`, 419 retry, `parseApiError()` → `{status, message, code, fieldErrors}` |
| `lib/types.ts` | TS mirrors of the Laravel Resources. **Update whenever a Resource changes.** |
| `lib/money.ts` | The only cents ↔ "$129.99" conversion. Never `parseFloat(x) * 100`. |
| `lib/dates.ts` | Calendar dates in UTC, timestamps in local zone |
| `lib/queryClient.ts`, `lib/cn.ts` | Query defaults, class-name helper |
| `hooks/` | `useFormFields`, `useQueryFilters` (filters in URL), `useDebouncedValue`, `useFilePreview`, `useFlash` |
| `components/ui/` | Small in-house components (Button, Field, Alert, Badge, Card, Dialog, Table, Pagination, ...) |
| `components/layout/` | RootLayout, PublicLayout (Navbar), DashboardLayout |
| `features/<area>/` | `queries.ts` (all API hooks of the area) + its pages: `auth`, `tours`, `bookings`, `account`, `operator`, `admin` |
| `pages/` | Feature-less pages (home, about, 404, error) |

Import alias: `@/` = `src/`.

## 4. Invariants — never break these

These are the reason the project exists. Each is enforced in code **and**
usually backed by a database constraint and a test. Breaking one is a bug
even if every test passes.

**Trust**
1. The browser is never trusted. Prices, totals, discounts, the current user,
   record owners, roles and file types are decided on the server.
2. The server computes every price via `PricingService::quote()`; the quote
   endpoint and the real booking share it. The client sends only *what* it
   wants (`spots`, `payment_method`, `discount_code`).
3. Frontend guards and hidden buttons are UX, not security. Every rule is
   enforced by the API.

**Authorization**
4. Access is declared on **route groups** in `routes/api.php`. A new endpoint
   goes inside the right group; never add ad-hoc role checks in controllers.
5. Record ownership is decided by **policies** (`Gate::authorize(...)`).
6. Lists of owned data start from the user: `$request->user()->tours()`.

**Input / output**
7. Every input is validated (Form Request, or `$request->validate()` for ≤2–3
   fields). Controllers read only `validated()` / `safe()->only()`. Never
   `$request->all()` into a model.
8. Every output goes through a Resource. Never return a model or expose a new
   column without deciding it belongs in the Resource.
9. Sensitive columns are **not** fillable (`role`, `booked_spots`,
   `balance_cents`, `operator_id`, `image_path`, profile `status`, ...). They
   are set explicitly by the code that owns them. Models use `#[Fillable([...])]`.

**Money**
10. Money is integer cents everywhere (DB, PHP, JSON, TS). Columns end in
    `_cents`. Rounding is explicit integer math (`intdiv(x * pct + 50, 100)`).
11. Bookings snapshot the unit price.
12. Wallet = balance + **append-only** ledger (`wallet_transactions`); rows are
    never updated or deleted. Every balance change goes through `WalletService`.

**Database & concurrency**
13. Multi-step writes run in one `DB::transaction`. Lock (`lockForUpdate()`)
    **inside** the transaction, **before** reading the values you check.
14. Never call an external service (payment provider, mail, HTTP) while holding
    a lock or inside a transaction. See the card flow in `BookingService`.
15. Critical rules are also DB constraints (`CHECK`, FK `restrictOnDelete`,
    unique / partial unique indexes). New critical rules get a constraint too.
16. Never edit a migration that has run anywhere. Always add a new one, with a
    working `down()`.
17. Statuses/roles are PHP enums **and** `CHECK` constraints; keep them in sync.
18. Model `$attributes` mirror DB defaults (strict mode needs it); DB-computed
    columns (e.g. `duration_days`) require `$model->refresh()` after save.
19. Every list eager-loads (`with(...)`). Strict mode throws on lazy loading.
20. SQL uses bound parameters only. LIKE patterns go through `Search::containsPattern`.

**Domain**
21. Users are suspended, never deleted (financial history must stay traceable).
22. A broken business rule throws a `BusinessRuleException` named constructor
    with a stable `snake_case` code. Add a new constructor rather than ad-hoc
    `new BusinessRuleException(...)`. Codes are an API contract.
23. Notifications are queued and sent **after** commit.
24. Uploads: validate by content (`image|mimes:jpg,jpeg,png,webp|max:5120`, no
    SVG) → re-encode with `ImageUploader` → random name. Only `index.php` executes.
25. No state change on GET. No `dangerouslySetInnerHTML`, no `innerHTML`.
26. Session cookies (HttpOnly) only. Never store tokens in `localStorage`.
27. Never weaken: rate limiters, login lockout, identical auth error messages
    (no user enumeration), `safeRedirect()`, session regeneration, signed URLs.

## 5. Coding conventions

**PHP**
- PSR-12 via Laravel Pint (`make pint`). 4-space indent, LF, final newline (`.editorconfig`).
- Typed everything: parameters, return types, `readonly` constructor promotion.
  Services are `final` and injected through the constructor.
- Named arguments for calls with several scalars (see `BookingController::store`).
- `match` over `switch`; enums over strings; `CarbonImmutable` dates.
- Class-level `/* | ... */` comment block explaining the *why* for every new
  class and every non-obvious method. Comment what would go wrong without
  the code. Don't comment the obvious *what*.
- Guarded (non-fillable) columns are written deliberately by their owning
  code with `forceFill([...])` (see `BookingService::createBooking`), never by
  widening `#[Fillable]`.
- Nested `DB::transaction()` calls become savepoints; private helpers like
  `BookingService::releaseSpots()` open their own so they are safe both
  standalone and inside an outer transaction.
- Business logic in services, never in controllers, models (beyond
  relations/casts/scopes/small accessors), Form Requests or Resources.

**TypeScript / React**
- Strict TS; no `any`, no non-null `!` to silence the compiler, no unused code.
- 2-space indent. Function components and hooks only.
- Components never call `api` directly — only hooks from the feature's
  `queries.ts`. Query keys are arrays, general → specific (`['tours','detail',id]`),
  built by an exported key factory per feature (`bookingKeys`, `tourKeys`,
  `userKey`). Reuse the factory; never hand-write a key string elsewhere.
- Responses are typed with `Envelope<T>` (`{data}`) and `Paginated<T>` from `lib/types.ts`.
- After a mutation, **invalidate** affected keys; don't hand-patch the cache
  (exception: setting `['user']` after login/logout).
- Server state = React Query; UI state = `useState`. Never copy server data
  into `useState` + `useEffect(fetch)`.
- Filters live in the URL (`useQueryFilters`).
- Every page handles pending / error (`parseApiError(e).message`) / empty
  states and starts with `<PageHeader>` (sets `<title>`).
- Forms show Laravel's 422 messages via `fieldErrors`; HTML `required`/`min`
  are convenience only.
- Accessibility: labelled controls (`Field.tsx`), real buttons/links,
  visible focus, keyboard support.
- Multipart updates use `POST` + `_method=PUT`.

**Naming**
- Tests: `test_<the_rule_in_plain_english>` in snake_case.
- Migrations: `make:migration add_<column>_to_<table>` / `create_<table>_table`.
- Constraints: `<table>_<rule>` (e.g. `tours_no_overbooking`).

## 6. How to make a change (the senior-engineer workflow)

Work like a careful senior engineer on a team, not like a code generator.

1. **Understand before editing.** Read the relevant docs chapter, the code
   path end to end (route → middleware → request → controller → service →
   model → resource → frontend hook → page), and the existing tests. Find the
   existing pattern and follow it. Don't re-solve what is already solved.
2. **Plan, and surface decisions.** For anything beyond a trivial fix, state
   the plan: files to touch, data-model impact, API contract impact, risks,
   and alternatives considered. When a real design choice exists (e.g. "policy
   vs Form Request vs service"), lay out the trade-offs and get agreement
   before writing code. Ask when a requirement is genuinely ambiguous; don't
   guess on product decisions (refund policy, limits, wording).
3. **Test first.** Write the failing test that states the rule, run it, see
   it fail for the right reason. Then implement. A test never seen failing
   proves nothing.
4. **Smallest correct change.** Stay in scope. No drive-by refactors, renames
   or reformatting of untouched code; propose them separately. Prefer extending
   existing abstractions over new ones; add an abstraction only when a second
   real use exists.
5. **Walk every layer** a change touches (see recipes in §7). A field that
   reaches the DB but not the Resource, the TS type or the test is unfinished.
6. **Defence in depth.** For critical rules: validation + service rule + DB
   constraint + test. Think about concurrency (two requests at once), failure
   halfway (rollback), and the hostile client (curl, not our UI).
7. **Backward compatibility.** Don't change response shapes, error codes,
   route URLs or enum values without updating every consumer and saying so.
   Migrations must work on existing data (backfill, nullable-then-required).
8. **Keep docs alive.** Update code comments you invalidate, the relevant
   `docs/` chapter, and add an entry to `docs/decisions.md` (context →
   decision → consequences, next `D<n>`) for any new architectural decision
   or rejected alternative. Update this file if a fact here changed. A finished
   exercise also gets a write-up in `docs/exercises/` (§13).
9. **Verify.** Before calling work done, run (or ask the user to run):
   `make test`, `make pint`, `make npm c="run build"`, `make npm c="run lint"`,
   plus a manual click-through as each affected role for UI changes. Report
   results honestly, including what was not verified.
10. **Self-review the diff** as a reviewer would: security (§4), N+1, missing
    eager loads, missing tests for failure paths, leftover debug code, dead
    code, unclear names, comments that lie.

### Project skills (`.claude/skills/`)

Use the matching skill instead of improvising the procedure:

| Skill | When |
|---|---|
| `lt-brainstorm-plan` | Before any feature/exercise: clarify, explore, options, written plan for approval |
| `lt-tdd` | Any behaviour change: red → green → refactor, coverage checklist |
| `lt-schema-change` | Migrations, constraints, enums, the layer walk for a new field |
| `lt-endpoint` | New or changed API endpoint |
| `lt-frontend-page` | New or changed React page/hook |
| `lt-debugging` | Any failure or surprise: root cause before fix |
| `lt-review` | Before declaring done, or reviewing a diff |
| `lt-exercise` | Start/finish an exercise end to end (orchestrates the others) |

### Definition of done

- [ ] Failing test written first; now passes; failure paths and side effects asserted (not just status codes)
- [ ] All invariants in §4 still hold
- [ ] Every touched layer updated (migration, model, request, resource, policy/route group, TS type, hook, page)
- [ ] `make test`, `make pint`, frontend build + lint pass (or explicitly reported as not run)
- [ ] Comments, `docs/`, `docs/decisions.md`, and this file updated where affected
- [ ] For an exercise: `docs/10-exercises.md` marked implemented, §11 status set, write-up in `docs/exercises/` and its index row
- [ ] Any dev data changed during manual checks restored
- [ ] Diff is minimal, in scope, self-reviewed

## 7. Change recipes

**New column / field** (docs/04 "Changing the schema")
new migration (+ `down()`, constraint if critical) → model `#[Fillable]` (only
if client-settable), `casts()`, `$attributes` default → Form Request rules →
Resource → factory → `lib/types.ts` → form / display → feature test.

**New endpoint**
choose the route group (audience) in `routes/api.php`, add throttle if
abusable → Form Request → thin controller method → service method for rules
→ policy for ownership → Resource → tests: happy path, each 4xx (401, 403
per wrong role, 404, 422), and side effects → `AccessControlTest` if access
rules are involved → frontend hook in `queries.ts` → page/route.

**New business rule**
service method + `BusinessRuleException::<name>()` with a stable code → DB
constraint if it protects data integrity → test asserting the `code` and that
nothing changed → frontend reacts to `code` if needed.

**New page** (docs/07 §7.10)
backend first + tested → type → hook → page with pending/error/empty →
route in `router.tsx` (guard if needed, `lazy` for operator/admin) → nav link
→ build + lint → click through as guest and each role.

**New scheduled job / command**
`app/Console/Commands/` reusing services (make logic public deliberately and
document why) → register in `routes/console.php` → test with
`$this->travel(...)` and `artisan(...)`.

**New payment gateway**
implement `PaymentGateway` → bind in `AppServiceProvider` by
`config('payments.gateway')` → confirm via verified webhook, never the
browser redirect → card data never touches our server.

## 8. Testing rules (docs/08)

- Feature tests hit real HTTP and real Postgres (`lebanon_tourism_test`,
  `RefreshDatabase`). Never switch to SQLite.
- Arrange / Act (one request) / Assert response **and** DB state (`->fresh()`).
- One behaviour per test; the name states the rule.
- Factories: `User::factory()` states `withBalance(cents)`, `unverified()`,
  `admin()`, `operator(OperatorStatus::X)` (default approved), `suspended()`;
  `Tour::factory()` states `draft()`, `started()`, plus `->for($op, 'operator')`;
  `Discount::factory()->fixed(cents)` / `->percent(n)`. Base `TestCase` adds
  SPA `Origin`/`Referer` headers and `$this->traveler(balanceCents: ..)`
  (a verified traveler).
- Fakes: `Notification::fake()`, `Storage::fake('public')`; swap the gateway
  with `$this->app->instance(PaymentGateway::class, FakePaymentGateway::decliningAll())`.
- Time: `$this->travel(20)->minutes()`.
- Gotchas: `$this->app['auth']->forgetGuards()` after logout in the same test;
  fake uploads get MIME from the file name.
- Security fixes always get a test that performs the attack.

## 9. Never do

- Trust client-sent prices, ids of owners, roles, or file types
- Return models directly, or `$request->all()` into `create()`/`update()`
- Put business rules in controllers or the frontend
- Use floats for money, or compute prices in JavaScript
- Edit an already-run migration; drop constraints to make a test pass
- Call external services inside a transaction / while holding a lock
- Delete users or ledger rows
- Weaken or skip tests, rate limits, CSRF, signed URLs, or strict mode to "make it work"
- Add CORS, JWT/localStorage auth, a second origin, or a UI/state library
- Use `dangerouslySetInnerHTML` / `innerHTML`, or GET for state changes
- Commit secrets or `.env` files; set `APP_DEBUG=true` in production
- Run `make fresh` / destructive DB commands or `git` history rewrites without the user's OK
- Claim something works without having seen it work

## 10. Docs map

| Need | Read |
|---|---|
| Why a choice was made | `docs/decisions.md` (D1–D17) |
| Setup / troubleshooting | `docs/01-getting-started.md` |
| Request path, folder map | `docs/02-architecture.md` |
| Sessions, CSRF, roles, policies | `docs/03-authentication.md` |
| Schema, constraints, money, locking | `docs/04-database.md` |
| Booking, pricing, wallet, card flow | `docs/05-bookings-and-payments.md` |
| Security fixes and proofs | `docs/06-security.md` |
| Frontend structure and patterns | `docs/07-frontend.md` |
| Tests | `docs/08-testing.md` |
| Production | `docs/09-deployment.md` |
| Planned work | `docs/10-exercises.md` |
| What each finished exercise did | `docs/exercises/` (one `.md` per exercise; add one when an exercise lands) |

## 11. Current work: the exercises (`docs/10-exercises.md`)

Agreed order: reading exercises first, then 1 → 2 → 3, then 5, 6, 4, 8, 7,
then 12, 11, 9, 10 (recurring departures last: it refactors booking/capacity
that #5 and #9 depend on). Update the status column as work lands.

| # | Exercise | Key notes | Status |
|---|---|---|---|
| R | Reading exercises | Password broker, Sanctum stateful, route middleware audit | todo |
| 1 | Meeting point | Optional `meeting_point` (string 255, public) on tours; migration `2026_11_10_000100`, 5 tests in `TourManagementTest` / `TourCatalogTest` | done: verified 2026-11-24 (90 tests pass, build + lint + pint OK, shown on tour page) |
| 2 | Price limit | `max:1000000` cents in `TourRequest` (was $100,000) + readable message + form hint; validation only, no CHECK (D17); 3 tests in `TourManagementTest` | done: merged 2026-11-24 (PR #1; 93 tests, pint, build, lint, manual check OK) |
| 3 | Sold-out badge | Frontend only; uses `available_spots`, `is_bookable` | todo |
| 5 | Expire stale pending bookings | Artisan command, 15 min; `BookingService::releaseSpots()` is private today, so expose a deliberate public method; schedule in `routes/console.php` (scheduler runs only in prod compose: test the command directly); `travel()` | todo |
| 6 | Admin moderation lock | `moderated_at`; discuss policy vs request vs service first | todo |
| 4 | Change e-mail | `pending_email` + signed link to new address; swap on click | todo |
| 8 | Review photos | `review_photos` table, max 3, `photos.*`, delete files with review | todo |
| 7 | CSP header | `docker/nginx/prod.conf`; check console in prod stack | todo |
| 12 | Audit log | `audit_events` for admin actions + admin page | todo |
| 11 | Faster search | `pg_trgm` GIN or `tsvector`; `EXPLAIN ANALYZE` on 100k tours | todo |
| 9 | Stripe gateway | Checkout + signed webhook confirms; bind when `PAYMENT_GATEWAY=stripe` | todo |
| 10 | Recurring departures | `departures` table; plan data migration before code | todo |

## 12. Git

- Remote: `origin` = github.com/Mohammadabouali-svg/Lebanon_tourism, default
  branch `main`. History starts with a single baseline commit (the old PHP
  project's history was replaced on purpose). Exercise 1 is in that baseline.
- **One branch per exercise or change**, cut from an up-to-date `main`:
  `exercise/<nn>-<short-name>` (e.g. `exercise/03-sold-out-badge`). Create it
  before the first edit.
- Small, focused commits. Message = imperative summary line + a body that
  says *why*. Run Pint and the tests before committing.
- **No attribution of any kind**: no `Co-Authored-By` lines, no "Generated
  with ..." footers, no mention of AI tools in commits, PR titles or PR
  descriptions. Commits are authored by the user's git identity only.
- **Commit, push and open PRs only when the user asks.** The user reviews and
  merges PRs themselves; never merge, enable auto-merge or delete branches
  unless asked.
- After the user merges: `git switch main`, `git pull --ff-only`, and confirm
  `main` equals `origin/main`. Deleting the merged branch is the user's call.
- Never force-push, rewrite history or run destructive git commands without
  the user's explicit OK.

## 13. Working with the user

This is university coursework: the user must be able to explain and defend
every change. That shapes how agents work here.

- **Explain the reasoning in plain language** as you go: what you found, what
  you are about to do, and why. Prefer a short table of decisions over prose.
- **Plan first, then wait.** For anything beyond a trivial fix, present the
  plan (`lt-brainstorm-plan`) and **wait for explicit approval** before
  creating a branch or editing files. Lay out each real decision with
  options, trade-offs and a recommendation, and let the user choose.
- **Verify, don't trust.** Facts given in a prompt, a hand-off or an old doc
  ("the rule is missing", "no row is above X") are hypotheses: check them in
  the code or the database, and say whether they held.
- **Stay strictly in scope.** One exercise or task at a time. Problems spotted
  in passing are reported as follow-ups, not fixed silently.
- **Show the red run.** Report how each new test failed before the change
  (status code or assertion). Say honestly when a test already passed before
  the change (e.g. a boundary guard) instead of presenting it as red.
- **Report results as seen**: exact pass counts and command outcomes, what was
  not verified, and anything that failed or was skipped.
- **Manual checks touch dev data only** (demo accounts on localhost). Note the
  original values first, restore them afterwards, and say that you did.
- **Never run destructive commands** (`make fresh`, `migrate:fresh`, dropping
  data, deleting branches, `git reset --hard`) without asking first.
- **Exercise write-ups.** Every finished exercise gets
  `docs/exercises/<nn>-<short-name>.md` with the sections *What was asked*,
  *Decisions*, *What was done* (table by layer), *Tests* (including the red
  run) and *Verification*, plus a row in `docs/exercises/README.md`.
- **Next ADR number**: read the last `D<n>` in `docs/decisions.md`, and update
  the range in §10 when you add one.

## 14. Local environment notes

These facts come from real sessions on the user's Windows PC. Check them on
the machine you are on: they are fallbacks, not requirements.

**Before anything:** `docker compose ps`. If the stack is down,
`docker compose up -d`.

**No `make`** (Windows without WSL): run the Makefile recipes as plain
`docker compose` commands from the project root.

| `make` target | Equivalent |
|---|---|
| `make up` / `make down` | `docker compose up -d` / `docker compose down` |
| `make test` | `docker compose exec -T php php artisan test` |
| `make test-filter f=X` | `docker compose exec -T php php artisan test --filter=X` |
| `make pint` | `docker compose exec -T php ./vendor/bin/pint` (check only: `--test`) |
| `make migrate` | `docker compose exec -T php php artisan migrate` |
| `make artisan c="..."` | `docker compose exec -T php php artisan ...` |
| `make npm c="run build"` | `docker compose exec -T frontend npm run build` |
| `make npm c="run lint"` | `docker compose exec -T frontend npm run lint` |
| SQL shell | `docker compose exec -T db psql -U lebanon_tourism -d lebanon_tourism` |

- In Git Bash, prefix commands that pass container paths with
  `MSYS_NO_PATHCONV=1`, or MSYS rewrites them into Windows paths.
- **OneDrive folder means slow bind mounts.** The full suite takes about
  1.5–2 minutes; login and save in the app take 10–20 s. Wait instead of
  assuming something is broken, and run the full suite in the background.
- **Never run two test runs at once**: they share `lebanon_tourism_test`, and
  `RefreshDatabase` makes them clash. Tests added while a run is in progress
  may be picked up by it.
- Composer timing out: set `COMPOSER_PROCESS_TIMEOUT=0`. If `tsc` complains
  that `@typescript/typescript-linux-x64` is missing, stop the `frontend`
  container and re-run `npm ci` in a one-off container.
- Port 8080 busy on the host: set `API_PORT=8090` (or another free port) in
  the root `.env`. The app is still http://localhost:5173.
- Symlinks: Windows without Developer Mode cannot create them, and git here
  uses `core.symlinks=false`. That is why `CLAUDE.md` imports this file with
  `@AGENTS.md` instead of being a symlink.
- Never put personal data (e-mail addresses, tokens) in docs, commits, PRs or
  requests to outside services.

## 15. Splitting work and handing off

When several agents (or sub-agents, or later sessions) work on the project:

- **One writer per set of files.** Backend and frontend of the same feature
  may be split, but the backend contract (Resource shape, error codes) is
  agreed and tested **first**, and `frontend/src/lib/types.ts` mirrors it.
- **Read-only roles** (exploration, review, security audit) can run alongside
  a writer. Reviewers report findings; they do not edit.
- **Migrations are serialised.** Only one agent creates migrations at a time,
  so timestamps and schema assumptions don't collide.
- No agent commits, pushes, opens PRs, runs destructive commands or rewrites
  history without the user's explicit OK.
- Agents that do not load skills automatically open
  `.claude/skills/<name>/SKILL.md` and follow it (table in §6).

**A hand-off prompt** (used to start the next task in a fresh session) is
self-contained and follows this outline:

1. **Scope**: the single task, and "don't touch anything else".
2. **Context**: coursework, read this file first, the current state of `main`
   (last merged PR or commit), where the previous write-ups are.
3. **The task**: its text from `docs/10-exercises.md`.
4. **What was found already**: concrete files, lines and facts, explicitly
   marked "check it yourself"; say if the task may already be partly done.
5. **Decisions to lay out** with trade-offs, for the user to choose.
6. **Workflow**: the skills in order (`lt-exercise` → `lt-brainstorm-plan`,
   wait for approval → branch → `lt-tdd` → `lt-review` → docs → verify).
7. **Verification**: suite, Pint, build, lint, manual check per role, restore data.
8. **Environment and git rules**: point to §12 and §14, and repeat the
   critical ones (no attribution; commit, push and PR only when asked).
9. End with: "Start by reading AGENTS.md and the files above, then give me the plan."

## 16. Git hosting (GitHub CLI)

- `gh` is installed at `C:\Program Files\GitHub CLI\gh.exe` and may not be on
  the Git Bash `PATH`: call it by full path (`"/c/Program Files/GitHub CLI/gh.exe"`).
- GitHub requests from this machine sometimes fail with "Empty reply from
  server" or "unexpected EOF". Retry; PowerShell `git`/`gh` has worked when
  Git Bash failed. Afterwards, re-read the state (`git log`, `gh repo view`)
  rather than assuming whether the failed call applied.
- PR descriptions follow §12 (no attribution) and summarise: what changed,
  decisions, tests (with the red run), verification results and docs touched.
