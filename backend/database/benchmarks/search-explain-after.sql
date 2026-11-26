-- ---------------------------------------------------------------------------
-- Exercise 11, AFTER the change: the same measurement as
-- search-explain-before.sql, for the queries GET /api/tours?search=... runs
-- now (copied from Laravel's query log). Run against lebanon_tourism_bench,
-- migrated with 2026_11_26_000400_add_search_indexes_to_tours:
--
--   docker compose exec -T db psql -U lebanon_tourism -d lebanon_tourism_bench \
--       -v pattern='%hike%' -v today=2026-11-26 -f - < backend/database/benchmarks/search-explain-after.sql
--
-- Like the controller, it first looks up the matching city ids and binds
-- them into `city_id in (...)`; when no city matches, only the title is
-- searched.
-- ---------------------------------------------------------------------------

\pset pager off

-- The city lookup (~25 rows) the controller runs first.
EXPLAIN (ANALYZE, BUFFERS)
select "id" from "cities" where "name"::text ilike :'pattern';

select count(*) > 0 as has_cities, coalesce(string_agg(id::text, ','), '') as city_ids
from "cities" where "name"::text ilike :'pattern' \gset

\if :has_cities

EXPLAIN (ANALYZE, BUFFERS)
select count(*) as "aggregate" from "tours" where "status" = 'published' and exists (select * from "users" where "tours"."operator_id" = "users"."id" and exists (select * from "operator_profiles" where "users"."id" = "operator_profiles"."user_id" and "status" = 'approved')) and exists (select * from "departures" where "tours"."id" = "departures"."tour_id" and "starts_on"::date > :'today') and ("title"::text ilike :'pattern' or "city_id" in (:city_ids));

EXPLAIN (ANALYZE, BUFFERS)
select "tours".*, (select avg("reviews"."rating") from "reviews" where "tours"."id" = "reviews"."tour_id") as "reviews_avg_rating", (select count(*) from "reviews" where "tours"."id" = "reviews"."tour_id") as "reviews_count" from "tours" where "status" = 'published' and exists (select * from "users" where "tours"."operator_id" = "users"."id" and exists (select * from "operator_profiles" where "users"."id" = "operator_profiles"."user_id" and "status" = 'approved')) and exists (select * from "departures" where "tours"."id" = "departures"."tour_id" and "starts_on"::date > :'today') and ("title"::text ilike :'pattern' or "city_id" in (:city_ids)) order by (select MIN(starts_on) from "departures" where "departures"."tour_id" = "tours"."id" and "starts_on"::date > :'today') asc, "id" asc limit 12 offset 0;

\else

EXPLAIN (ANALYZE, BUFFERS)
select count(*) as "aggregate" from "tours" where "status" = 'published' and exists (select * from "users" where "tours"."operator_id" = "users"."id" and exists (select * from "operator_profiles" where "users"."id" = "operator_profiles"."user_id" and "status" = 'approved')) and exists (select * from "departures" where "tours"."id" = "departures"."tour_id" and "starts_on"::date > :'today') and ("title"::text ilike :'pattern');

EXPLAIN (ANALYZE, BUFFERS)
select "tours".*, (select avg("reviews"."rating") from "reviews" where "tours"."id" = "reviews"."tour_id") as "reviews_avg_rating", (select count(*) from "reviews" where "tours"."id" = "reviews"."tour_id") as "reviews_count" from "tours" where "status" = 'published' and exists (select * from "users" where "tours"."operator_id" = "users"."id" and exists (select * from "operator_profiles" where "users"."id" = "operator_profiles"."user_id" and "status" = 'approved')) and exists (select * from "departures" where "tours"."id" = "departures"."tour_id" and "starts_on"::date > :'today') and ("title"::text ilike :'pattern') order by (select MIN(starts_on) from "departures" where "departures"."tour_id" = "tours"."id" and "starts_on"::date > :'today') asc, "id" asc limit 12 offset 0;

\endif
