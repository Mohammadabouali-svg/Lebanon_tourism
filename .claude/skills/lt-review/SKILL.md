---
name: lt-review
description: Use before declaring any Lebanon Tourism change done, or when asked to review a diff. A senior-engineer review against the project's invariants - security, money, concurrency, data integrity, API contract, tests, docs and code quality.
---

# Senior review checklist

Review the whole diff as if someone else wrote it. Report findings by
severity (blocker / should fix / nit), each with file:line and a concrete
failure scenario. A reviewer reports; fixing is a separate step.

## Security and trust (blockers)
- [ ] No client-supplied price, total, owner, role or status is trusted
- [ ] New routes are in the correct group in `routes/api.php`; ownership checked by a policy
- [ ] Input validated; only `validated()`/`safe()` used; no `$request->all()`
- [ ] No sensitive column added to `#[Fillable]`
- [ ] Output via a Resource; no secret or internal field leaked
- [ ] No GET state change; throttle on abusable writes
- [ ] Uploads go through validation + `ImageUploader`; files cleaned up on delete
- [ ] Frontend: no `dangerouslySetInnerHTML`, no tokens in storage, `safeRedirect` intact
- [ ] Auth messages still don't reveal whether an account exists

## Money, data and concurrency (blockers)
- [ ] Integer cents only; explicit rounding
- [ ] Ledger append-only; balance changed only through `WalletService`
- [ ] Multi-step writes in one transaction; row locked before the check
- [ ] No external call inside a transaction or while holding a lock
- [ ] Critical rules backed by a DB constraint; enums and CHECKs in sync
- [ ] New migration (none edited), with `down()` and a plan for existing rows

## Contract and behaviour
- [ ] Response shapes, error codes, URLs and enum values unchanged, or every consumer updated
- [ ] `frontend/src/lib/types.ts` matches the Resources
- [ ] Mutations invalidate every affected query key
- [ ] Lists eager-load (no N+1) and paginate

## Tests
- [ ] A test was written first and covers the rule's failure paths and side effects
- [ ] Access matrix covered (guest, wrong role, unverified, suspended, not owner)
- [ ] No test weakened, skipped or deleted
- [ ] `make test`, `make pint`, frontend build and lint were run, with results stated

## Quality and docs
- [ ] Diff is in scope: no drive-by refactors or reformatting
- [ ] Names are clear; logic in the right layer (CLAUDE.md §3)
- [ ] *Why* comments added or updated; none left lying
- [ ] `docs/` chapter, `docs/decisions.md` ADR (if a decision was made), and the CLAUDE.md status table updated
- [ ] No debug code, dead code or TODOs without an owner
