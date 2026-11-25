# Exercise 6: Admin moderation lock

## What was asked

> Today an operator can re-publish a tour an admin archived. Add a
> `moderated_at` column; while it is set, the operator cannot change the
> status. Where does this rule belong: Form Request, policy or service?
> (Discuss the trade-offs before choosing.)
> ([10-exercises.md](../10-exercises.md))

## What we found first

| Fact | Where |
|---|---|
| The admin status change validated inline, set `status` and saved directly: no service, no transaction, no record that an admin acted | `Admin\TourController::updateStatus()` |
| Operator saves go `TourRequest` → `TourService::update()` → private `save()`, which already locks the tour row to check `booked_spots` | `Operator\TourController`, `TourService` |
| `TourRequest` requires `status`, so **every** operator save sends one, even when only the title changes | `TourRequest` |
| `status` is fillable; operators set it by mass assignment | `Tour` |
| `TourPolicy::update()` = approved operator and owner; `moderate()` = admin; nothing checked moderation | `TourPolicy` |
| Only `TourService` (after this change) writes a tour's status; the old admin controller was the only other writer | grep over `app/` |
| `docs/decisions.md` D16 listed the admin lock as deliberately left out | D16 |

All findings from the hand-off held when checked in the code.

## Decisions

The discussion the exercise asks for, in short (full version: **D19** in
[decisions.md](../decisions.md)):

| Option | For | Against |
|---|---|---|
| Form Request (`after()` hook) | error lands under the `status` field for free | reads the stored tour **before** the transaction: an admin archive in between is missed (race); business rule in the validation layer |
| Policy (`update()` refuses, or a `changeStatus` ability) | "who may do what" feel | the operator *may* edit their tour; refusing `update()` blocks every edit; a `changeStatus` ability needs input comparison in the controller; 403 "unauthorized" is the wrong message |
| **Service** (chosen) | checked under the existing row lock (no race, invariant 13); stable code `tour_moderated` (invariant 22); every future caller gets the rule | the form maps the code to the status field itself (one line) |

All three run on the server, so curl cannot get round any of them; the
differences are the race, the meaning of the error, and the layer.

| Question | Choice | Why |
|---|---|---|
| Where the rule lives | `TourService`, `BusinessRuleException::tourModerated()` (422, `tour_moderated`) | see above |
| When the lock is set / cleared | Admin **archive** sets it (keeps the first time when re-archiving); any other admin status (published, draft) clears it; admin publishing does not lock | Solves exactly the exercise's case; no new endpoint, button or access-matrix rows |
| What is locked | Only a **status change** by the operator. Same-status saves and all other fields still work | The form always sends a status; fixing content is how a tour gets reinstated |
| Delete of a locked tour | Unchanged (still refused when it has bookings) | Out of the exercise's scope; listed as a follow-up |
| Admin side | Moved into `TourService::moderate()`: transaction, row lock, `forceFill` | Both halves of the rule in one class; thin controller |
| Database | Nullable `timestampTz moderated_at`, not fillable, default null, `immutable_datetime` cast; **CHECK `tours_moderated_means_archived`** | The CHECK states what the lock means (integrity, unlike D17's price cap) and holds even when PHP is bypassed. All existing rows are NULL |
| API / UI | `moderated_at` in `TourResource` (additive, backward compatible) | Null on every published tour (thanks to the CHECK), so nothing leaks publicly |

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `backend/database/migrations/2026_11_25_000100_add_moderated_at_to_tours.php` | `moderated_at` column + `tours_moderated_means_archived` CHECK; `down()` drops both |
| Model | `backend/app/Models/Tour.php` | cast, `$attributes` default, "not fillable" comment |
| Factory | `backend/database/factories/TourFactory.php` | `moderated()` state (archived + locked a day ago) |
| Exception | `backend/app/Exceptions/BusinessRuleException.php` | `tourModerated()` → `tour_moderated` |
| Service | `backend/app/Services/TourService.php` | new `moderate()`; `save()` reads `booked_spots`, `status`, `moderated_at` from the **locked** row and refuses a status change on a locked tour |
| Controller | `backend/app/Http/Controllers/Api/Admin/TourController.php` | `updateStatus()` calls `TourService::moderate()` |
| Resource | `backend/app/Http/Resources/TourResource.php` | `moderated_at` (ISO 8601 or null) |
| Types | `frontend/src/lib/types.ts` | `moderated_at: string \| null` |
| Operator form | `frontend/src/features/operator/TourFormPage.tsx` | status select disabled with the reason when locked; `tour_moderated` shown under the select |
| Operator list | `frontend/src/features/operator/OperatorToursPage.tsx` | "Locked by admin" badge |
| Admin list | `frontend/src/features/admin/AdminToursPage.tsx` | "Locked" badge (date for screen readers) |
| Docs | `docs/03-authentication.md`, `docs/04-database.md`, `docs/10-exercises.md`, `docs/decisions.md` (D16, D19), `AGENTS.md` §10 + §11, this write-up + README row | |

No route, policy, Form Request or query hook changed, so the access matrix
(`AccessControlTest`) is untouched.

## Tests

| Test | Proves |
|---|---|
| `TourManagementTest::test_an_operator_cannot_republish_a_tour_an_admin_archived` | 422 `tour_moderated`; DB still archived, title unchanged (whole save rolled back), `moderated_at` unchanged |
| `TourManagementTest::test_an_operator_can_edit_a_moderated_tour_without_changing_its_status` | same-status save → 200, title changed, lock kept |
| `TourManagementTest::test_an_operator_can_republish_a_tour_they_archived_themselves` | an operator's own archive does not lock |
| `TourManagementTest::test_the_operator_sees_the_lock_on_their_tour` | `data.moderated_at` on the operator's tour |
| `AdministrationTest::test_archiving_a_tour_locks_it_against_operator_changes` | admin archive sets `moderated_at` (response and DB) |
| `AdministrationTest::test_publishing_an_archived_tour_removes_the_lock` | the admin "unlock" path |
| `AdministrationTest::test_publishing_a_tour_does_not_lock_it` | only archiving locks |
| `AdministrationTest::test_the_lock_is_shown_in_the_admin_tour_list` | `moderated_at` in the admin list |
| `AdministrationTest::test_the_database_refuses_a_moderated_tour_that_is_not_archived` | raw `UPDATE` to published throws `QueryException` (the CHECK) |

### Red run

The column, cast, default and factory state were added first (no behaviour),
so the tests could fail on assertions instead of "column does not exist". The
migration had no CHECK yet. Result: **6 failed, 3 passed**:

- `cannot_republish`: `Expected response status code [422] but received 200.`
- `archiving_a_tour_locks_it`: `Failed asserting that false is true.` (no `moderated_at` in the response)
- `publishing_an_archived_tour_removes_the_lock`: `moderated_at` still set
- `the_lock_is_shown_in_the_admin_tour_list` / `the_operator_sees_the_lock`: `Failed asserting that null is identical to '2026-11-24T…'`
- `the_database_refuses…`: `Failed asserting that exception of type "QueryException" is thrown.`

The 3 that passed already are guards for behaviour that must **not** change:
same-status save on a locked tour, an operator's own archive, admin publish
not locking. After the change: `AdministrationTest` + `TourManagementTest`
**32 passed (114 assertions)**.

## Verification (2026-11-25)

| Check | Result |
|---|---|
| Full backend suite before the change | 102 passed (348 assertions) |
| Full backend suite after | 111 passed (374 assertions) |
| Pint | pass (142 files) |
| Frontend build (`tsc -b` + vite) | pass (exit 0) |
| Frontend lint (oxlint) | 0 warnings, 0 errors |
| `migrate` on the dev DB | `2026_11_25_000100_add_moderated_at_to_tours` DONE |
| Manual, `admin@`, admin tours list | "Bekaa Wine Route" set to Archived → DB `archived` + `moderated_at` set; list shows "Archived" + "Locked" |
| Manual, `operator@`, My tours | "Archived · Locked by admin" |
| Manual, `operator@`, edit form | status select disabled on Archived with "Archived by an administrator on 25 Nov 2026, 16:32 …" |
| Manual, `operator@`, select re-enabled from the console, Published, Save (hostile client) | `422 {"code":"tour_moderated"}`; banner "Please correct the highlighted fields." and the message under the status select; DB unchanged (`updated_at` not even touched) |
| Manual, `operator@`, title changed only | saved, still Archived + Locked |
| Manual, `admin@`, set back to Published | DB `published`, `moderated_at` NULL, no badge |

Dev data used for the manual check, then restored:

| Item | Original | During | Restored |
|---|---|---|---|
| Tour 6 "Bekaa Wine Route" `status` | published | archived | published ✔ |
| Tour 6 `moderated_at` | NULL | 2026-11-25 14:32:14+00 | NULL ✔ |
| Tour 6 `title` | Bekaa Wine Route | "… (edited while locked)" | Bekaa Wine Route ✔ |
| Tour 6 `updated_at` | 2026-11-24 14:38:49 | changed by the saves | set back in SQL ✔ |
| Tour 6 itinerary (2 items) | same content | re-saved by the form | same content, **new row ids** (the service replaces the itinerary on every save) |

Not verified: two simultaneous requests (admin archive and operator publish
at the same moment); both paths take the same row lock, which is the guarantee,
but no test runs them in parallel.

## Things to know

- To lift the lock but keep the tour hidden, an admin sets it to **draft**.
- Follow-ups: should an operator be able to **delete** a locked tour that has
  no bookings? Should the operator be notified (e-mail) when an admin archives
  their tour? Should the admin record a reason? (Exercise 12's audit log would
  record who archived and when.)
