---
name: lt-tdd
description: Use when implementing any behaviour change, bug fix or exercise in Lebanon Tourism. Strict red-green-refactor with Laravel feature tests against Postgres in Docker, asserting responses and database side effects.
---

# Test-driven development for Lebanon Tourism

The iron rule: **no production code without a failing test that demands it.**
A test you never saw fail proves nothing.

## Red
1. Pick the test file next to similar tests (`backend/tests/Feature/<Area>/`,
   `tests/Unit/` for pure functions like money and discount maths).
2. Name it after the rule: `test_operators_cannot_set_a_price_above_ten_thousand_dollars`.
3. Arrange with factories and helpers (see CLAUDE.md §8), act with **one**
   request, assert the response **and** the state afterwards:
   ```php
   $this->actingAs($traveler)
       ->postJson("/api/tours/{$tour->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet'])
       ->assertUnprocessable()
       ->assertJsonPath('code', 'insufficient_funds');
   $this->assertSame(0, $tour->fresh()->booked_spots);
   ```
4. Run it: `make test-filter f=<name fragment>`.
5. Confirm it fails **for the right reason** (an assertion, not a typo or a
   missing class). Fix the test if it fails for the wrong reason.

## Green
6. Write the minimum code to pass, in the right layer (CLAUDE.md §6–7).
7. Run the single test, then the whole area (`make test-filter f=BookingTest`).

## Refactor
8. Clean up names and duplication, and add the *why* comments. Tests stay green.
9. Run `make test` (full suite) and `make pint`.

## Coverage checklist for a feature
- [ ] Happy path, with side effects asserted (DB rows, balances, spots, files, notifications)
- [ ] Each access failure: guest 401, wrong role 403, unverified 403, suspended 403, someone else's record 403/404
- [ ] Validation 422 per invalid field (`assertJsonValidationErrors`)
- [ ] Each business rule 422 with its `code`, and proof nothing changed
- [ ] Hostile input: extra fields ignored (price, owner, role), negative/zero numbers, huge values
- [ ] Time-based rules use `$this->travel(...)`
- [ ] Critical rules: a test proving the **DB constraint** rejects the bad row even when PHP is bypassed

## Rules
- Real Postgres only (never SQLite). Use fakes for side effects:
  `Notification::fake()`, `Storage::fake('public')`, gateway via `$this->app->instance(...)`.
- Never weaken, skip or delete an existing test to get green. If an old test
  now looks wrong, stop and discuss it.
- If you cannot run Docker from your shell, write the test and code, then
  hand the user the exact commands, and report the step as **not verified**.
