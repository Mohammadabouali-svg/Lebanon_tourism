# Exercise 2: Price limit

## What was asked

> Operators may not set a price above $10,000. Write the failing test first
> (the test is already sketched in chapter 08).
> ([10-exercises.md](../10-exercises.md))

## What we found first

The rule was not missing, it was too loose. `TourRequest` already had
`'price_cents' => ['required', 'integer', 'min:100', 'max:10000000']`, which
is **$100,000** (prices are integer cents, invariant 10). So the job was to
**lower** the limit to `1000000` cents, not to add a new rule. Chapter 08
said "add the rule", so its wording was corrected.

The database only has `tours_price_positive CHECK (price_cents > 0)`. No
tour in the demo data costs more than $390, and the factory makes $25-$500
prices, so the new limit breaks no existing row or test.

## Decisions

| Question | Choice | Why |
|---|---|---|
| Also a database `CHECK`? | **No**, validation only ([D17](../decisions.md#d17-the-tour-price-cap-is-validation-only-not-a-database-constraint)) | Invariant 15 is for rules that protect *data integrity* (no negative price, no overbooking). A $12,000 price is not corrupt data, it is above a business policy that may change. A CHECK would need a migration for every change, and adding it to a live table fails if any existing row is above the cap. The only way to write a price is the operator endpoint, which always runs `TourRequest`, so curl cannot bypass it. |
| Is exactly $10,000.00 allowed? | **Yes** | Laravel's `max` is inclusive. "Not above $10,000" means $10,000 is fine. A test pins this boundary. |
| Frontend | **Hint** "Between $1 and $10,000." under the field, no HTML `max` | The input is free text ("65.50"), so `max` would do nothing. The hint is only guidance; the server decides (invariant 3). Same wording as the admin wallet-credit form. |
| Error message | "The price may not be more than $10,000." | Laravel's default would say "must not be greater than 1000000": the limit in cents, meaningless to an operator. |

## What was done

| Layer | File | Change |
|---|---|---|
| Validation | `backend/app/Http/Requests/TourRequest.php` | `max:10000000` → `max:1000000`, a *why* comment, and a `messages()` method with the readable message |
| Tests | `backend/tests/Feature/Operator/TourManagementTest.php` | 3 new tests (below) |
| Operator form | `frontend/src/features/operator/TourFormPage.tsx` | `hint="Between $1 and $10,000."` on the price field |
| Docs | `docs/08-testing.md`, `docs/10-exercises.md`, `docs/decisions.md` (D17), `CLAUDE.md` §11 | Wording fixed, exercise marked implemented, decision recorded, status set to done |

No migration, model, Resource or TypeScript type changed: the response
shape is the usual 422 `{message, errors: {price_cents: [...]}}`.

## Tests

All in `backend/tests/Feature/Operator/TourManagementTest.php`:

- `test_prices_above_ten_thousand_dollars_are_refused`: POST with
  1 000 001 cents → 422, the exact message on `price_cents`, and **no tour
  row** in the database.
- `test_a_price_of_exactly_ten_thousand_dollars_is_accepted`: 1 000 000
  cents → 201 and stored.
- `test_the_price_limit_also_applies_when_updating_a_tour`: PUT with
  1 000 001 cents → 422 and the stored price is **unchanged** (14 000).

### Red, then green

Before the change (red), for the right reason:

```
⨯ prices above ten thousand dollars are refused
  Expected response status code [422] but received 201.
✓ a price of exactly ten thousand dollars is accepted
⨯ the price limit also applies when updating a tour
  Expected response status code [422] but received 200.
```

The boundary test passes even before the change, and that is expected: the
old limit was higher. It is there to catch an off-by-one later (for example
someone writing `max:999999`).

After the change (green): all 14 tests of `TourManagementTest` pass.

## Verification (2026-11-24)

| Check | Result |
|---|---|
| Full backend suite | 93 passed (302 assertions) |
| Pint `--test` | pass |
| Frontend build (`tsc -b` + vite) | pass |
| Frontend lint (oxlint) | 0 warnings, 0 errors |
| Manual, as `operator@`: tour "Cedars Snowshoe Weekend" ($210.00), price $10,000.01 | API 422, form shows "The price may not be more than $10,000." under the field |
| Manual: price $10,000 | Saved (database: 1000000 cents) |
| Manual: restore | Price put back to $210.00 (21000 cents) |

## Things to know

- A tour that was created before this change with a price above $10,000
  would stay readable and bookable, but the operator would have to lower its
  price before saving any other edit. There is no such tour in the demo data.
- Seeders or other server code could still store a higher price, because
  there is deliberately no database constraint (see D17).
