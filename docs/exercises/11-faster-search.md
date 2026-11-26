# Exercise 11: Faster search

## What was asked

> `ILIKE '%term%'` cannot use a normal index, so it gets slow with many
> tours. Try Postgres' `pg_trgm` extension with a GIN index, or full-text
> search (`to_tsvector`). Measure before and after with `EXPLAIN ANALYZE` on
> 100,000 generated tours. ([10-exercises.md](../10-exercises.md))

Done before Exercise 12 (audit log), by choice; AGENTS.md §11 records the
new order (10, 11, 12, 9).

## What we found first

| Fact | Where / how checked |
|---|---|
| The search is `title ILIKE :p OR EXISTS (city name ILIKE :p)` inside `visibleToPublic()`, `upcoming()`, `orderByNextDeparture()` and `paginate()` (so a `COUNT(*)` with the same filters runs too) | `Api\TourController::index()`, real SQL from Laravel's query log |
| Laravel **does** cast: `"title"::text ilike ?` (the hand-off said it did not). It turned out not to block the index | query log; EXPLAIN on the bench DB |
| `tours` had only `tours_pkey`; Postgres does not index foreign keys; `docs/04` said "Foreign keys are indexed by `foreignId()->constrained()`" (false) | `pg_indexes`, dev DB |
| `pg_trgm` 1.6 available, not installed; its control file says `trusted = true` | `pg_available_extensions`, `pg_trgm.control` |
| Postgres already turns the city `EXISTS` into a one-off hashed subplan, but that still sits on one side of an `OR` | EXPLAIN |
| Other `ILIKE` searches in the admin area (tours, users, bookings) | out of scope, follow-up |

## Decisions

Full version: **D24** in [decisions.md](../decisions.md).

| Question | Choice | Rejected alternative and why |
|---|---|---|
| a. Technique | `pg_trgm` + GIN: same "contains, ignoring case" results | Full-text search: whole words/prefixes, "byb" would stop finding Byblos; both: no requirement for it; GiST: slower reads |
| b. What to index, query shape | `tours.title` (trigram) **and** `tours.city_id` (btree); city ids looked up first and bound: `title ILIKE ? OR city_id IN (...)` | `OR EXISTS`: no index usable (measured); `city_id = ANY(ARRAY(subquery))`: index usable but the planner guessed 34,662 tours for 0 and scanned all departures; index on `cities.name`: 25 rows |
| c. Other indexes | only `city_id`, because the measurement showed the `OR` needs it; FK claim in docs corrected | `operator_id`, `category_id`, `status`: not needed by the search plans; follow-up |
| d. Migration | one migration, extension + both indexes, inside its transaction; `down()` drops them, extension without `CASCADE` | `CONCURRENTLY`: no transaction, half-done state on failure; milliseconds of lock here |
| e. Benchmark | throwaway DB `lebanon_tourism_bench`; SQL `generate_series`, deterministic; scripts committed in `backend/database/benchmarks/`, raw plans in [11-faster-search/](11-faster-search/) | dev or test DB (would mix benchmark data with demo data / be wiped); factories (very slow here) |
| f. Tests | characterisation of the semantics + index exists + "the planner *can* use the index" on the real SQL | plan-text assertions (brittle) |
| h1. Terms < 3 characters | nothing special: they work and scan | `min:3` (would change what users can search) |

## What was done

| Layer | File | Change |
|---|---|---|
| Migration | `2026_11_26_000400_add_search_indexes_to_tours.php` | `CREATE EXTENSION IF NOT EXISTS pg_trgm`, GIN `tours_title_trgm (title gin_trgm_ops)`, `tours_city_id_index`; `down()` reverses all three |
| Controller | `Api/TourController.php` | search: `City::whereLike('name', $pattern)->pluck('id')`, then `title ILIKE ? OR city_id IN (...)` (title only when no city matches) |
| Tests | `TourCatalogTest.php`, `TourSearchIndexTest.php` (new) | see below |
| Benchmark | `backend/database/benchmarks/search-100k.sql`, `search-explain-before.sql`, `search-explain-after.sql`, `run-search-benchmark.sh` | data generator, the exact SQL before/after, runner (6 runs per term) |
| Docs | `04-database.md`, `08-testing.md`, `10-exercises.md`, `decisions.md` (D24, D16), `AGENTS.md` §10/§11, this file | FK index claim corrected; search section |

No change to routes, Resources, the API contract or the frontend.

## The benchmark

**Data** (`search-100k.sql`, derived from the row number, so identical on
every run): 100,000 tours, 250,000 departures, 200 operators (90% approved),
24 cities from `CatalogSeeder`, 90% published / 5% draft / 5% archived, 15%
of tours with only past dates. Titles are adjective + activity + "through
the" + scenery from 20 words each: "Hike" is in 5,000 titles (4,500 visible),
"Zaatar" in 10, no title contains a city name. `ANALYZE` after loading.

**Method.** `EXPLAIN (ANALYZE, BUFFERS)` of the exact COUNT and page queries
Laravel sends, 6 runs per term; tables show the median of runs 2–6 (warm
cache; a truly cold cache would need a database restart, not done).

A first BEFORE run was discarded: the 10 "Zaatar" titles were all drafts
(`g % 10000 = 0` also means `g % 20 = 0`), so the rare term had no visible
result. The generator was fixed (`g % 10000 = 57`), the data reloaded, and
BEFORE measured again. That first run also showed a different plan (85,000
primary-key lookups, 1–3 s) from slightly different statistics after
`ANALYZE`: on the same query, plans depend on statistics.

**Results** (ms, median of runs 2–6):

| Term | Visible tours | COUNT before | COUNT after | Page before | Page after | Plan on `tours` before → after |
|---|---|---|---|---|---|---|
| hike (common) | 4,500 | 485 | 59 | 431 | 83 | Seq Scan → BitmapOr (trgm + city_id) |
| zaatar (rare) | 10 | 386 | 3.9 | 453 | 1.3 | Seq Scan → Bitmap Index Scan (trgm) |
| byb (city) | 4,167 | 531 | 27 | 511 | 38 | Seq Scan → BitmapOr |
| by (2 letters) | 4,167 | 478 | 109 | 508 | 100 | Seq Scan → Seq Scan |
| 100% | 0 | 290 | 0.8 | 202 | 0.5 | Seq Scan → Bitmap Index Scan (trgm) |
| qqqzzz (none) | 0 | 234 | 0.5 | 194 | 0.5 | Seq Scan → Bitmap Index Scan (trgm) |

The city lookup added before the search query costs 0.1–0.2 ms.

**Reading the plans.**
- Before, every query read all 100,000 tours and hashed all ~214,000
  upcoming departures, whatever the term.
- With only the index added (experiment, rolled back), the plan was still a
  Seq Scan: the `OR` had a subplan on one side.
- "by" has no trigram and still scans `tours`, yet got faster: with real
  city ids the planner estimates the matches correctly and checks departures
  per tour through `departures_one_per_day` instead of hashing all of them.
- "hike" stays the slowest: 4,500 matches must all be sorted by their next
  departure to find the first 12.

## Tests

| Test | Before the change |
|---|---|
| `TourCatalogTest::test_search_matches_a_substring_inside_a_word_ignoring_case` | **already passed** (characterisation) |
| `TourCatalogTest::test_search_with_a_two_letter_term_still_matches` | **already passed** (characterisation) |
| `TourCatalogTest::test_search_returns_tours_matching_the_title_or_the_city_together` | **already passed** (characterisation; also checks order and `meta.total`) |
| `TourSearchIndexTest::test_tour_titles_have_a_trigram_gin_index` | red: "The index tours_title_trgm does not exist" |
| `TourSearchIndexTest::test_the_planner_can_use_the_trigram_index_for_the_public_search` | red *with the index in place*: the old `OR EXISTS` query cannot use it |
| `TourSearchIndexTest::test_the_planner_can_use_both_indexes_when_a_city_matches` | red: no `tours_title_trgm` in the plan |

The planner tests capture the real SQL of a real request, then `EXPLAIN
(FORMAT JSON)` it with sequential, index and index-only scans switched off
(`SET LOCAL`), and assert an index name from the JSON, not plan text.

**A mistake on the way.** The first version only set `enable_seqscan = off`.
It passed alone but failed twice in the full suite: earlier tests leave
`tours` with other size statistics, and the planner then read all of `tours`
through `tours_pkey` (an index scan, but not of the search condition). A GIN
index can only be read by a bitmap scan, so switching index and index-only
scans off too leaves "a bitmap scan driven by the search condition" as the
only free path. Checked that the fixed tests are still red on the old query
(controller change stashed, 2 failed), then green on the new one.

## Verification

| Check | Result |
|---|---|
| Baseline suite on `main` | 204 passed |
| Full suite on the branch | 210 passed (823 assertions) |
| `pint --test` | pass (170 files) |
| Frontend `npm run build` / `npm run lint` | exit 0 / 0 warnings, 0 errors (no frontend change) |
| `migrate` on dev | `tours_title_trgm`, `tours_city_id_index`, `pg_trgm` 1.6 created |
| `migrate:rollback --step=1` + `migrate` on dev (with the user's OK) | rollback left only `tours_pkey` and `plpgsql`; re-migrate restored both indexes and `pg_trgm`; 11 tours untouched |
| Benchmark database | `lebanon_tourism_bench` dropped at the end (with the user's OK); rebuild it with the scripts in `backend/database/benchmarks/` |
| API vs the old SQL on dev data (Byblos, tyre, byb, by, zzqq, 100%) | identical ids and order |
| Browser as guest: Byblos, tyre, byb, by, 100% | same tours as the old query; "100%" shows the empty state; only the expected guest `GET /api/user` 401s in the console |
