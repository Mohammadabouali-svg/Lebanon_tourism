# Exercise 4: Change e-mail address

## What was asked

> Let users change their e-mail. The new address must be verified before it
> replaces the old one (otherwise a typo locks the user out, and an attacker
> with a stolen session could take the account over). Hint: store
> `pending_email`, send a signed link to it, swap on click.
> ([10-exercises.md](../10-exercises.md))

## What we found first

| Fact | Where |
|---|---|
| No way to change the e-mail: the profile request only accepts `name` and `phone` | `UpdateProfileRequest` |
| `email` **is** fillable (sign-up and admin creation set it by mass assignment), so adding it to the profile request would have changed it instantly, unverified | `User` `#[Fillable]`, `RegistrationController`, `Admin\UserController` |
| Profile routes are in `auth:sanctum` + `active`, not `verified` | `routes/api.php` |
| The verification e-mail is the pattern: relative signed route, React page, `hash_equals(sha1(email))`, 60 min | `VerifyEmailNotification`, `EmailVerificationController` |
| E-mails are lower-cased by a mutator and before `unique` at sign-up; `users.email` has a unique index | `User::email()`, `RegisterTravelerRequest` |
| `password_reset_tokens` is keyed by **e-mail**: a token for the old address would survive a swap and work on whoever signs up with that address next | users migration, password broker |
| The login lockout is keyed by e-mail + IP: unaffected | `LoginRequest` |

All hand-off findings held when checked; the reset-token point turned out to
be a real hole, not just a question.

## Decisions

Full version: **D20** in [decisions.md](../decisions.md).

| Question | Choice | Rejected alternative and why |
|---|---|---|
| Data model | One nullable `users.pending_email`, not fillable, lower-cased; CHECK `users_pending_email_differs` | `email_changes` table: history and several open requests are not needed (Exercise 12 is the audit log) |
| Unique on `pending_email`? | **No**: uniqueness is enforced on `email` at swap time | A unique index would let anyone block an address by requesting it first |
| Re-authentication | `current_password` required | Without it a stolen session alone is enough |
| "Address taken" at request time | Ordinary 422 field error | Hiding it gains nothing: the public sign-up form already reveals it, and this endpoint needs login + password + rate limit |
| "Address taken" at swap time | 422 `email_taken` (`BusinessRuleException::emailTaken()`), `exists()` check under the row lock + unique-index violation caught as backstop | — |
| The link | Relative signed route, `sha1(pending_email)`, 60 min, no login needed | A login requirement breaks "opened on the phone"; the signature proves the inbox |
| At the swap | `email` = new, `pending_email` = null, `email_verified_at` = now, delete old reset tokens; sessions untouched | Ending all sessions would log out the real owner as easily as an attacker, and the password did not change |
| Old-address notice | After the swap, queued, after commit | At request time: earlier, but without a cancel link it cannot stop anything |
| Cancel / resend | `DELETE user/email-change`; requesting again overwrites (= resend) | A separate resend would skip the password check |
| Rate limit | `throttle:6,1` on the request (each one sends an e-mail) | — |
| Where the logic lives | `EmailChangeService` (final); Form Request shapes input; thin controller | Rules in the controller (§5) |
| `email` fillable? | Kept fillable (sign-up needs it); comment explains why changing it is never a mass update | Removing it touches code outside the exercise: possible separate clean-up |

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `backend/database/migrations/2026_11_25_000200_add_pending_email_to_users.php` | `pending_email` + `users_pending_email_differs` CHECK; `down()` drops both |
| Model | `backend/app/Models/User.php` | `$attributes` default, lower-case mutator, comment on why `email` stays fillable |
| Exception | `backend/app/Exceptions/BusinessRuleException.php` | `emailTaken()` → `email_taken` |
| Form Request | `backend/app/Http/Requests/RequestEmailChangeRequest.php` | lower-case, `email:rfc`, not current, `unique`, `current_password` |
| Service | `backend/app/Services/EmailChangeService.php` | `request()`, `cancel()`, `confirm()` (transaction, row lock, re-check, swap, reset-token cleanup, notify after commit) |
| Controller | `backend/app/Http/Controllers/Api/EmailChangeController.php` | `store` (202 + user), `destroy`, `confirm` |
| Notifications | `ConfirmEmailChangeNotification` (to new), `EmailChangedNotification` (to old) | queued, on-demand (`Notification::route('mail', …)`) |
| Routes | `backend/routes/api.php` | `POST`/`DELETE user/email-change` in the logged-in group; signed `POST email/change/{id}/{hash}` |
| Resource | `backend/app/Http/Resources/UserResource.php` | `pending_email` (additive; only for the user themself and admins) |
| Comment | `backend/app/Http/Requests/UpdateProfileRequest.php` | no longer says "left as an exercise" |
| Types | `frontend/src/lib/types.ts` | `pending_email: string \| null` |
| Hooks | `frontend/src/features/account/queries.ts` | `useRequestEmailChange`, `useCancelEmailChange`, `useConfirmEmailChange` (all invalidate `userKey`) |
| Account page | `frontend/src/features/account/AccountPage.tsx` | "E-mail address" card: new address + password, "Waiting for confirmation" with Cancel |
| Confirm page | `frontend/src/features/account/ConfirmEmailChangePage.tsx`, `router.tsx` | public `/confirm-email-change`, pending / success / error states |
| Docs | `docs/03-authentication.md`, `docs/04-database.md`, `docs/10-exercises.md`, `docs/decisions.md` (D16, D20), `AGENTS.md` §10, §11, §14, this write-up + README row | |

## Tests

New file `tests/Feature/Auth/EmailChangeTest.php` (21) and two rows in
`AccessControlTest`:

| Test | Proves |
|---|---|
| `requesting_a_change_stores_the_pending_address_and_keeps_the_current_one` | 202; `email` unchanged, `pending_email` set (response + DB) |
| `the_confirmation_link_is_mailed_to_the_new_address` | on-demand mail to the new address, nothing to the user's current one |
| `a_wrong_current_password_is_refused` | 422 `current_password`, nothing stored, nothing sent |
| `an_address_already_in_use_is_refused_when_requesting` | 422 `email` (case-insensitive) |
| `the_current_address_is_refused` | 422 `email` |
| `the_pending_address_is_normalised_to_lower_case` | stored lower-case, trimmed |
| `requesting_a_change_is_rate_limited` | 7th request in a minute → 429, pending stays the 6th |
| `a_pending_change_can_be_cancelled` | `pending_email` null |
| `the_profile_endpoint_ignores_an_email_field` | `PUT user/profile` with `email` changes nothing |
| `a_valid_link_swaps_the_address_and_clears_the_pending_one` | guest click swaps |
| `confirming_marks_the_new_address_as_verified` | typo'd unverified sign-up becomes verified |
| `a_tampered_link_is_refused_and_changes_nothing` | other user id → 403 |
| `an_expired_link_is_refused_and_changes_nothing` | `travel(61)->minutes()` → 403 |
| `an_older_link_stops_working_after_a_newer_request` | 403, pending is the newer address |
| `a_link_is_refused_after_the_change_was_cancelled` | 403 |
| `a_used_link_cannot_be_used_again` | 403 |
| `an_address_registered_in_between_is_refused_at_swap_time` | 422 `email_taken`, nothing changed, no notice sent |
| `the_old_address_is_notified_after_the_swap` | notice to the old address naming the new one, only after the swap |
| `reset_tokens_for_the_old_address_are_deleted` | the reset-token hole is closed |
| `the_new_address_can_log_in_and_the_old_one_cannot` | end-to-end login |
| `the_database_rejects_a_pending_address_equal_to_the_current_one` | raw `UPDATE` → `QueryException` naming the CHECK |
| `AccessControlTest::guests_cannot_request_or_cancel_an_email_change` | 401 |
| `AccessControlTest::a_suspended_user_cannot_request_an_email_change` | 403, nothing stored |

### Red run

All tests written before any code: **22 failed, 1 passed**. The routes did
not exist, so every HTTP test failed with `Expected response status code
[202|422|401|403] but received 404`; the CHECK test failed with `column
"pending_email" of relation "users" does not exist`. The one that passed is
`the_profile_endpoint_ignores_an_email_field`: a characterisation test
pinning behaviour that already held and must keep holding. After the change:
**23 passed (111 assertions)**.

## Verification (2026-11-25)

| Check | Result |
|---|---|
| Full backend suite before the change | 111 passed (374 assertions) |
| Full backend suite after | 134 passed (485 assertions) |
| Pint | pass (1 import-order fix in the new test file) |
| Frontend build (`tsc -b` + vite) | pass |
| Frontend lint (oxlint) | 0 warnings, 0 errors |
| Migration `down()` then `up()` on the test DB | both DONE |
| Manual, `traveler2@`, wrong password | "The password is incorrect" under the field |
| Manual, request `traveler2.new@lebanon-tourism.test` | "Waiting for confirmation" panel; DB `email` unchanged, `pending_email` set |
| Mailpit | "Confirm your new e-mail address" to the **new** address |
| Tampered link (id 6 → 5) | 403, "This link cannot be used" |
| Real link | "Your e-mail address is now traveler2.new@…"; DB swapped, pending NULL, verified now |
| Mailpit | "Your e-mail address was changed" to the **old** address |
| Same link again | 403, refused |
| Log in with the old address | "These credentials do not match our records." |
| Log in with the new address | OK, account page shows the new address |

**Found during the manual check:** the first confirmation e-mail failed in the
queue with `Route [email-change.confirm] not defined`. The worker was a
25-hour-old process that had loaded the routes before they were added; the
tests fake notifications, so they could not see it. Restarting the `queue`
container and `queue:retry all` delivered it. No code change was needed; the
lesson is in AGENTS.md §14 (production already runs `queue:restart` on deploy,
docs/09).

Dev data used for the manual check, then restored:

| Item | Original | During | Restored |
|---|---|---|---|
| User 6 `email` | traveler2@lebanon-tourism.test | traveler2.new@lebanon-tourism.test | original ✔ |
| User 6 `email_verified_at` | 2026-11-24 14:38:45 | 2026-11-25 15:51:51 | original ✔ |
| User 6 `pending_email` | NULL | traveler2.new@… then NULL | NULL ✔ |
| User 6 `updated_at` | 2026-11-24 14:38:45 | changed | original ✔ |
| `password_reset_tokens` / `failed_jobs` | 0 / 0 | 0 / 1 (the queue failure) | 0 / 0 ✔ (retried) |
| Mailpit | empty | 2 test e-mails | left in place (dev inbox only) |

Not verified by hand: the **expired** link (it would take 60 minutes; covered
by `an_expired_link_is_refused_and_changes_nothing` with `travel()`); two
users confirming the same address at the same moment (the unique-index
backstop is in the code, but no test runs requests in parallel).

## Things to know

- To get a new link, request the change again; it replaces the old one.
- Follow-ups: should a user suspended *after* requesting still be able to
  confirm? Should the old-address notice carry a signed "this wasn't me" link?
  Should `email` leave `#[Fillable]` (with `forceFill` at sign-up)? An admin
  view of pending changes?
