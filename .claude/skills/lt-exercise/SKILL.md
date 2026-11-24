---
name: lt-exercise
description: Use when the user wants to start, continue or finish one of the numbered exercises in docs/10-exercises.md of Lebanon Tourism. Orchestrates branch setup, planning, TDD implementation, review and status tracking for one exercise.
argument-hint: "[exercise number]"
---

# Working on an exercise

Exercise list and agreed order: `docs/10-exercises.md` and the status table
in CLAUDE.md §11.

## Start
1. Identify the exercise (argument or ask). Read its text in
   `docs/10-exercises.md` and the chapters it links to.
2. Git: if the folder is not a repository yet, offer to `git init` and commit
   the baseline first (ask the user). Then create
   `exercise/<nn>-<short-name>` from a clean tree, with the user's OK.
3. Confirm the stack runs: `make up`, then `make test` is green **before**
   changing anything, so later failures are yours.

## Plan
4. Run `lt-brainstorm-plan`. Exercises that explicitly ask for a discussion
   (e.g. #6 "policy vs Form Request vs service", #10 "plan the data migration
   first") must have that discussion written into the plan, and the chosen
   option recorded as an ADR in `docs/decisions.md`.

## Build
5. Follow `lt-tdd`, using `lt-schema-change`, `lt-endpoint` and
   `lt-frontend-page` for the layers involved. Keep each step small and green.

## Finish
6. Run `lt-review` on the full diff and fix the findings.
7. Update the docs:
   - the chapter the feature belongs to
   - `docs/10-exercises.md`: mark the exercise done or link the implementation
     (keep the exercise text for future readers)
   - `docs/decisions.md` if a decision was made
   - D16 "deliberately left out" in `docs/decisions.md`, if the feature was listed there
   - the CLAUDE.md §11 status table → `done`
8. Report: what changed (by layer), test names added, the verification
   commands and their results, anything not verified, and suggested follow-ups.
   Commit only when the user asks.
