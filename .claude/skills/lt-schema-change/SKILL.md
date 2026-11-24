---
name: lt-schema-change
description: Use when adding or changing database columns, tables, indexes, constraints or enum values in Lebanon Tourism. Covers safe Postgres migrations, CHECK constraints, model/enum sync and every layer a new field must reach.
---

# Changing the schema safely

Source of truth: `backend/database/migrations/` (each one is commented).
Background: `docs/04-database.md`.

## Rules
- **Never edit a migration that has run anywhere.** Create a new one:
  `make artisan c="make:migration add_meeting_point_to_tours"`.
- Every migration has a working `down()`.
- Money is `unsignedInteger('<name>_cents')`, never float or decimal.
- Foreign keys: `foreignId()->constrained()->restrictOnDelete()` (history
  must survive), `cascadeOnDelete()` only for owned children (itinerary items, photos).
- Critical rules become constraints with plain SQL, named `<table>_<rule>`:
  ```php
  DB::statement('ALTER TABLE tours ADD CONSTRAINT tours_price_max CHECK (price_cents <= 1000000)');
  ```
  and `down()` drops them (`ALTER TABLE ... DROP CONSTRAINT ...`).
- New status/role values: update the PHP enum **and** the `*_status_valid`
  CHECK (drop and re-add it in the new migration).
- "One X per Y while Z": a partial unique index (see `bookings_one_discount_per_user`).
- Index the columns used in frequent `WHERE`/`ORDER BY`.
- Existing data: add a column nullable, backfill, then add `NOT NULL` or the
  constraint. Write the backfill inside the migration and test it on demo data (`make fresh`).
- Generated values: `storedAs(...)`, then the service must `$model->refresh()` after save.

## Layer walk for a new field
1. Migration (+ constraint if critical)
2. Model: `#[Fillable]` **only** if the client may set it; `casts()`;
   `$attributes` default mirroring the DB default (strict mode needs it)
3. Factory `definition()` / a state if useful
4. Form Request rules (+ `attributes()` label)
5. Service, if a rule uses it
6. Resource: expose it deliberately
7. `frontend/src/lib/types.ts`, then the form and display
8. Seeder demo data, if it helps the demo
9. Tests: validation, persistence, Resource output, constraint

## Verify
`make migrate`, `make test`, then `make fresh` to prove migrations run from
zero together with the seeders. Check `down()`:
`make artisan c="migrate:rollback --step=1"` then `make migrate`.
Ask the user before running `make fresh` if they may have local data they care about.
