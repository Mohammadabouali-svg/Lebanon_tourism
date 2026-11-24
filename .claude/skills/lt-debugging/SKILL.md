---
name: lt-debugging
description: Use when a test fails, a request errors, the Docker stack misbehaves or anything behaves unexpectedly in Lebanon Tourism. Systematic root-cause debugging - reproduce, gather evidence, form one hypothesis, prove it, fix with a regression test.
---

# Systematic debugging

Never guess-and-patch. Find the root cause first; a fix without a known
cause is a new bug waiting.

## 1. Reproduce
- Smallest reliable reproduction: one test (`make test-filter f=...`) or one
  request (curl or the browser network tab), with exact input and output.
- If it can't be reproduced, collect more evidence before changing anything.

## 2. Gather evidence (read, don't assume)
- Full error and stack trace: `backend/storage/logs/laravel.log`, `make logs`,
  the `make test` output.
- Status code meaning on this stack:
  401 not logged in · 403 middleware/policy (`active`, `verified`, `role`,
  ownership) · 404 binding or `visibleToPublic` · **419 CSRF/session** (missing
  `ensureCsrfCookie`, wrong Origin, `SANCTUM_STATEFUL_DOMAINS`) · 422 validation
  (`errors`) or business rule (`code`) · 429 throttle · 500 see the log.
- Common project-specific causes:
  - `MissingAttributeException` / `LazyLoadingViolationException`: strict mode.
    Add a model `$attributes` default, `refresh()` after save for generated
    columns, or eager-load with `with()`. Don't turn strict mode off.
  - `SQLSTATE[23514]` check violation: a DB constraint caught a real bug
    upstream. Never drop the constraint.
  - A test is "logged in" after logout: `$this->app['auth']->forgetGuards()`.
  - Fake upload passes `image` but the uploader refuses it: expected (docs/08).
  - Frontend not reloading on `/mnt/c`: `VITE_USE_POLLING=true` in root `.env`.
  - Slow or flaky containers in OneDrive: sync/bind-mount issues (docs/01 troubleshooting).
- Inspect state: `make shell` → `php artisan tinker`, or psql on `localhost:54320`.
- `make artisan c="route:list --path=api -v"` for middleware questions.

## 3. One hypothesis at a time
- Write it down: "X happens because Y, so if I change Z, W will happen."
- Test it with the smallest change or an extra assertion or log line. Change
  one thing at a time. Revert experiments that didn't confirm the hypothesis.
- After 3 failed hypotheses, stop and re-read the code path from the top, or
  ask the user. Don't keep stacking fixes.

## 4. Fix
- First a failing regression test that reproduces the bug (`lt-tdd`).
- Fix the cause in the right layer, not the symptom (no try/catch to swallow
  it, no loosened validation, no disabled check).
- Run the full suite: `make test`. Remove any debug output.

## 5. Report
Root cause in one sentence, the fix, the regression test name, and anything
still unverified.
