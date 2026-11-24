# AGENTS.md — Lebanon Tourism

Instructions for any AI coding agent (Claude Code, Codex, Cursor, Copilot,
...) working in this repository.

**Read [CLAUDE.md](CLAUDE.md) in full before your first edit.** It is the
single source of truth for project facts, invariants, conventions, the
change workflow and the definition of done. This file only summarises the
rules that must never be missed and describes how agents should split and
hand off work. If the two ever disagree, CLAUDE.md wins. Fix the drift.

## Project in one paragraph

A tour booking platform for Lebanon (travelers, approved tour operators,
admins). Laravel 13 JSON API with Sanctum session cookies, PostgreSQL 18,
React 19 + TypeScript SPA, all in Docker Compose behind one origin. It is a
**teaching codebase**: every change must stay readable, commented where a
decision was made, documented in `docs/`, and covered by tests.

## Run things

Docker only, via `make` from a WSL/macOS terminal (not PowerShell):
`make test`, `make test-filter f=Name`, `make pint`,
`make npm c="run build"`, `make npm c="run lint"`, `make migrate`.
If your environment cannot run them, say so and hand the exact commands to
the user. Never report a result you have not seen.

## Non-negotiable rules (full list: CLAUDE.md §4)

1. Never trust the browser: prices, owners, roles and file types are decided server-side.
2. Access rules live on route groups in `backend/routes/api.php`; ownership in policies.
3. Validate every input (Form Request); return every output through a Resource; no `$request->all()`.
4. Money is integer cents everywhere. Wallet ledger is append-only, changed only via `WalletService`.
5. Multi-step writes are one transaction; lock rows before checking them; no external calls inside a transaction.
6. Critical rules are also DB constraints. Never edit an already-run migration.
7. Business-rule failures use `BusinessRuleException` named constructors with stable codes.
8. Users are suspended, never deleted. No state change on GET. No `dangerouslySetInnerHTML`. No tokens in localStorage.
9. Business logic lives in `app/Services`, never in controllers or the frontend.
10. Don't change response shapes, error codes, routes or enum values silently.

## Workflow every agent follows (full version: CLAUDE.md §6)

1. **Understand**: read the docs chapter and the full code path, and find the existing pattern.
2. **Plan**: list files, contract/data impact, risks, alternatives. Get agreement on real design choices.
3. **Test first**: write a failing test that states the rule, and see it fail.
4. **Implement** the smallest correct change across every layer it touches.
5. **Verify**: tests, pint, frontend build + lint, and a manual check per role for UI.
6. **Document**: comments, `docs/`, `docs/decisions.md` (new ADR), CLAUDE.md status table.
7. **Self-review** the diff against the invariants before reporting done.

## Project skills

Reusable step-by-step procedures live in `.claude/skills/<name>/SKILL.md`.
Agents that do not load skills automatically should open the matching file
and follow it:

| Skill | Use when |
|---|---|
| `lt-brainstorm-plan` | Before any feature or exercise: clarify, explore options, write the plan |
| `lt-tdd` | Implementing any behaviour change (red → green → refactor, in Docker) |
| `lt-schema-change` | Adding or changing columns, tables, constraints, enums |
| `lt-endpoint` | Adding or changing an API endpoint |
| `lt-frontend-page` | Adding or changing a React page or API hook |
| `lt-debugging` | Any failing test, error, or unexpected behaviour |
| `lt-review` | Reviewing a diff before calling work done |
| `lt-exercise` | Starting or finishing an exercise from `docs/10-exercises.md` |

## Splitting work between agents

When several agents (or sub-agents) work in parallel:

- **One writer per set of files.** Backend and frontend of the same feature
  may be split, but the backend contract (Resource shape, error codes) is
  agreed and tested **first**, and `frontend/src/lib/types.ts` mirrors it.
- **Read-only roles** (exploration, review, security audit) can run
  alongside a writer. Reviewers report findings; they do not edit.
- **Migrations are serialised.** Only one agent creates migrations at a time,
  so timestamps and schema assumptions don't collide.
- **Hand-offs are written down**: what was done, what was verified (with
  command output), what is left, and any decision still open.
- No agent commits, pushes, runs `make fresh`, or rewrites history without
  the user's explicit OK.
