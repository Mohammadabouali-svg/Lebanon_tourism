# Exercise 12: Audit log

## What was asked

> Record who changed what in the admin area (approvals, suspensions, wallet
> credits, tour status) in an `audit_events` table, and show it on a new
> admin page. ([10-exercises.md](../10-exercises.md))

## What we found first

| Fact | Where / how checked |
|---|---|
| All admin routes are one group `prefix('admin')->middleware(['verified', 'role:admin'])` inside `auth:sanctum` + `active` | `routes/api.php` |
| Suspend / unsuspend / create admin / approve / suspend operator were a `save()` in the controller, **no transaction**; suspend also deleted the user's `sessions` rows | `Admin\UserController`, `Admin\OperatorController` |
| The queue is **not** `after_commit` (`config/queue.php`), so a notification dispatched inside a transaction could be sent for a change that then rolls back | `config/queue.php`; `EmailChangeService::confirm()` already notifies after the transaction |
| Tour status already ran in `TourService::moderate()` (transaction + `lockForUpdate`, D19) but did not know which admin acted | `TourService` |
| Wallet credits already leave a ledger row with `created_by_id` (append-only, invariant 12) | `WalletService`, `wallet_transactions` migration |
| Morph map had only `booking` and `user` | `AppServiceProvider` |
| Operators **can delete** a tour without bookings, so an audited tour may disappear later | `TourService::delete()` |
| The app's database user **owns** the tables and is a superuser (dev), so `REVOKE` would not protect anything | `pg_tables.tableowner`, `pg_roles.rolsuper` |
| Repeating "suspend" overwrote the original `suspended_at` | red run of `test_suspending_an_already_suspended_user_records_nothing` |
| D20 calls the audit log "the place for history" (e-mail changes); those are self-service, not admin actions | `docs/decisions.md` D20; left as a follow-up |

## Decisions

Full version: **D25** in [decisions.md](../decisions.md).

| Question | Choice | Rejected alternative and why |
|---|---|---|
| a. Scope | 7 actions: the four asked + their reverses + creating an admin | Only the four (an undo or a new all-powerful account would be invisible); also discounts, catalogue, CSV export (follow-ups) |
| b. Where events are written | Explicitly, `AuditLog::record()` in the service, in the same transaction as the change; new `AdminUserService` | Eloquent observers (no reliable actor, fire for seeders/operators, miss non-model work); DB triggers that write events (DB does not know the admin) |
| c. Schema | `actor_id` FK `RESTRICT`; `action` enum + CHECK; polymorphic subject `user`/`tour` without FK; `changes jsonb` with only what changed; `created_at` only; indexes ending in `id` | Typed nullable FKs (block tour deletion or lose the id); whole-row snapshots (copy personal data); `reason` column (follow-up) |
| d. Append-only | Model has no update/delete path **and** triggers refuse `UPDATE`/`DELETE`/`TRUNCATE` | `REVOKE` (useless while the app owns the table); trigger is honest about its limit: the owner can drop it |
| e. Privacy | Store ids, statuses, amounts; names/e-mails/titles joined from current rows; never secrets | Copying names or the credit description into the event |
| f. Failure / concurrency | Refused actions write nothing; rollback removes both; lock the subject row, read "before" under the lock, record only real changes; e-mails after commit | Recording every request (duplicates when two admins click at once) |
| g. API + page | `GET /api/admin/audit-events` (admin group), filters `action`, `actor_id`, `subject_type`+`subject_id`, 25 per page, newest first; page `/admin/audit` | Date-range filter (not needed yet: id order + pagination) |

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `2026_11_26_000500_create_audit_events_table.php` | table, indexes, CHECKs `audit_events_action_valid` / `audit_events_subject_type_valid`, function `audit_events_refuse_change()` + triggers `audit_events_append_only` (row) and `audit_events_no_truncate` (statement); `down()` drops table and function |
| Enum | `app/Enums/AuditAction.php` | 7 cases, `<subject>.<what happened>` |
| Model | `app/Models/AuditEvent.php` | no fillable, no `updated_at`, casts, `actor()`, `subject()` (morphTo) |
| Provider | `AppServiceProvider.php` | morph map entry `tour` |
| Services | `AuditLog.php` (new), `AdminUserService.php` (new), `TourService::moderate()` | record inside the transaction; lock, compare, record only real changes; notify / invite after commit; `moderate()` takes the admin and syncs the route-bound tour with the locked row |
| Controllers | `Admin\UserController`, `Admin\OperatorController`, `Admin\TourController`, `Admin\AuditEventController` (new) | thin: call the service; new read-only index with validated filters and eager loads |
| Resource | `AuditEventResource.php` (new) | `id, action, actor {id,name,email}, subject {type,id,label}, changes, created_at` |
| Route | `routes/api.php` | `GET admin/audit-events` in the admin group |
| Frontend | `lib/types.ts`, `features/admin/queries.ts`, `features/admin/AdminAuditPage.tsx` (new), `router.tsx` | `AuditEvent` type, `useAuditEvents`, page with pending/error/empty, action filter and "only this admin" in the URL, lazy route + nav entry "Audit log" |
| Docs | `04-database.md`, `07-frontend.md`, `10-exercises.md`, `decisions.md` (D25), `AGENTS.md` §3/§10/§11, this file | |

The response shapes of the existing admin endpoints did not change.

## Tests

27 tests in `tests/Feature/Admin/AuditLogTest.php` (new), plus one row
`'read the audit log'` in `AccessControlTest::adminEndpoints()` (guest 401,
traveler and operator 403: 2 more test runs). Every test was run before the
code it tests:

| Test | Red run (before the change) |
|---|---|
| `test_every_audit_action_is_accepted_by_the_database` | table missing; then green with the table alone (it guards enum ↔ CHECK staying in sync) |
| `test_the_database_refuses_an_unknown_audit_action` | table missing; then, with the table but no CHECK: "exception … is not thrown" |
| `test_the_database_refuses_to_update_an_audit_event` / `_to_delete_an_audit_event` / `_to_truncate_the_audit_log` | table missing; then, without triggers: "exception … is not thrown" |
| `test_suspending_a_user_records_who_did_it`, `test_unsuspending_a_user_records_who_did_it`, `test_creating_an_admin_records_who_did_it_without_any_secret` | `ModelNotFoundException` (no event) |
| `test_suspending_an_already_suspended_user_records_nothing` | **red for a real reason:** the old code overwrote `suspended_at` ("two DateTime objects are equal" failed) |
| `test_a_refused_self_suspension_records_nothing`, `test_unsuspending_an_active_user_records_nothing`, `test_an_invalid_admin_creation_records_nothing` | **already passed** (nothing wrote events yet); they guard the new code |
| `test_approving_an_operator_records_the_status_change`, `test_suspending_an_operator_records_the_status_change` | no event |
| `test_approving_an_approved_operator_records_nothing_and_sends_nothing`, `test_approving_a_user_who_is_not_an_operator_records_nothing` | already passed |
| `test_a_wallet_credit_records_the_amount_and_the_ledger_entry`, `test_archiving_a_tour_records_the_status_and_the_lock`, `test_locking_a_tour_the_operator_archived_records_only_the_lock` | no event |
| `test_an_invalid_wallet_credit_records_nothing`, `test_setting_a_tour_to_its_current_status_records_nothing`, `test_an_invalid_tour_status_records_nothing` | already passed |
| `test_admins_see_the_audit_log_newest_first_with_current_names`, `test_the_audit_log_can_be_filtered_by_action_actor_and_subject`, `test_invalid_audit_log_filters_are_refused`, `test_an_event_about_a_deleted_tour_is_kept_without_a_label`, `test_unverified_and_suspended_admins_cannot_read_the_audit_log`, the two `AccessControlTest` data sets | 404 (no route) |

One surprise: `jsonb` does not keep key order (`{"to", "from"}`), so
`assertSame` on `changes` failed although the values were right. The tests
compare with `assertEquals` / scalar JSON paths; chapter 04 notes it.

No existing test was changed or weakened; `AdministrationTest` (14 tests)
passes unchanged on the new code.

## Verification

| Check | Result |
|---|---|
| Baseline before any change | 210 passed (823 assertions), exit 0 |
| Full suite after step 4 | 232 passed (904 assertions), exit 0 |
| Full suite at the end | 239 passed (938 assertions), exit 0, 336 s |
| `pint --test` | PASS, 178 files |
| `npm run build` (`tsc -b` + vite) / `npm run lint` | exit 0 / 0 warnings, 0 errors |
| `migrate` on the dev DB | `2026_11_26_000500` DONE; `\d audit_events` shows the 3 indexes, 2 CHECKs, FK `RESTRICT`, 2 triggers |
| Manual check as admin (localhost:5173) | approve then suspend `pending@`, suspend and reactivate `traveler2@`, credit `traveler2@` $1.00, archive then republish tour 10: 7 events, each with the right actor, subject and changes; all shown on `/admin/audit` with current names; the action filter works and is kept in the URL |
| Manual check as guest / traveler / operator | API 401 / 403 / 403; the page shows "This area is reserved for admin accounts." |
| `migrate:rollback --step=1` then `migrate` on dev (user's OK) | rollback dropped the table and the function `audit_events_refuse_change()`; re-migrate rebuilt the same indexes, CHECKs, FK and triggers |

Dev data: `pending@` was set back to `pending` (`approved_at` NULL) with SQL,
since no admin action returns an operator to "pending"; tour 10 was
republished through the app (moderation lock gone). The approval sent one
welcome e-mail to Mailpit, and suspending `traveler2@` ended their sessions.
With the user's OK, the test $1.00 credit was removed from `traveler2@`'s
wallet with SQL (dev data only; the app itself never deletes ledger rows,
invariant 12), so the balance is back to $300.00 with one ledger row, and the
7 audit events of the check were removed by the rollback above.

## Follow-ups

- Audit the bookings CSV export (a read of every traveler's personal data),
  and discounts / catalogue CRUD.
- An optional `reason` on admin actions.
- A less privileged database role for the app, so the triggers cannot be
  dropped by it; the same trigger for `wallet_transactions`.
- E-mail changes (D20) are self-service and not logged.
