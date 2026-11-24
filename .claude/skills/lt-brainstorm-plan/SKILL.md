---
name: lt-brainstorm-plan
description: Use before starting any feature, exercise or non-trivial change in Lebanon Tourism. Clarifies the requirement, explores the existing code path, weighs design options and produces a written plan for the user to approve before any code is written.
---

# Brainstorm, then plan (before writing code)

Goal: no code until the problem, the design and the plan are agreed.

## 1. Restate the requirement
- One paragraph: who (traveler / operator / admin), what changes, why.
- List open questions. Product decisions (limits, wording, refund rules,
  who may do what) belong to the user: **ask, don't guess**. Ask one
  question at a time, multiple choice when possible.

## 2. Explore what exists
- Read the matching `docs/` chapter (map in CLAUDE.md §10).
- Trace the path end to end: `routes/api.php` group → middleware → Form
  Request → controller → service → model/migration → Resource →
  `frontend/src/lib/types.ts` → feature `queries.ts` → page.
- Find the closest existing feature and its tests. The new code follows its pattern.
- Note which invariants (CLAUDE.md §4) the change touches.

## 3. Design options
For each real choice, give 2–3 options with trade-offs, then a recommendation:
- Where does the rule live: route group, policy, Form Request, service, DB constraint?
- Data model: new column vs new table; nullable; backfill for existing rows.
- API contract: new fields and error codes. Is it backward compatible?
- Concurrency and failure: what if two requests race, or it fails halfway?
- Security: what would a hostile client try (curl, not our UI)?

## 4. Write the plan
Present it in this shape and wait for approval:

```
Goal:
Decisions (with rejected alternatives):
Files to change (by layer):
  migration / model / request / policy / service / controller / resource / route
  tests (names of the tests to write first)
  frontend: types / queries / components / router
  docs: chapter(s), decisions.md ADR?, CLAUDE.md status
Risks & how they are covered:
Verification: commands + manual checks per role
```

Keep steps small enough that each ends in a green test run.

## 5. After approval
Follow `lt-tdd` for implementation. If something found mid-way invalidates
the plan, stop and re-plan with the user instead of improvising.
