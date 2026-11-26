-- ---------------------------------------------------------------------------
-- Exercise 11, BEFORE the change: EXPLAIN ANALYZE of the two queries GET /api/tours?search=...
-- runs (the paginate() COUNT and the page itself), copied from Laravel's
-- query log. Run against lebanon_tourism_bench only:
--
--   docker compose exec -T db psql -U lebanon_tourism -d lebanon_tourism_bench \
--       -v pattern='%hike%' -v today=2026-11-26 -f - < backend/database/benchmarks/search-explain-before.sql
--
-- `pattern` is what Search::containsPattern() produces ('%' + escaped term + '%').
-- Laravel uses unnamed server-side prepared statements, which Postgres plans
-- with the actual values, so literals here give the same plans as the app.
-- ---------------------------------------------------------------------------

\pset pager off

-- The COUNT(*) of paginate().
EXPLAIN (ANALYZE, BUFFERS)
select count(*) as "aggregate" from "tours" where "status" = 'published' and exists (select * from "users" where "tours"."operator_id" = "users"."id" and exists (select * from "operator_profiles" where "users"."id" = "operator_profiles"."user_id" and "status" = 'approved')) and exists (select * from "departures" where "tours"."id" = "departures"."tour_id" and "starts_on"::date > :'today') and ("title"::text ilike :'pattern' or exists (select * from "cities" where "tours"."city_id" = "cities"."id" and "name"::text ilike :'pattern'));

-- The first page (12 tours, default sort: soonest departure).
EXPLAIN (ANALYZE, BUFFERS)
select "tours".*, (select avg("reviews"."rating") from "reviews" where "tours"."id" = "reviews"."tour_id") as "reviews_avg_rating", (select count(*) from "reviews" where "tours"."id" = "reviews"."tour_id") as "reviews_count" from "tours" where "status" = 'published' and exists (select * from "users" where "tours"."operator_id" = "users"."id" and exists (select * from "operator_profiles" where "users"."id" = "operator_profiles"."user_id" and "status" = 'approved')) and exists (select * from "departures" where "tours"."id" = "departures"."tour_id" and "starts_on"::date > :'today') and ("title"::text ilike :'pattern' or exists (select * from "cities" where "tours"."city_id" = "cities"."id" and "name"::text ilike :'pattern')) order by (select MIN(starts_on) from "departures" where "departures"."tour_id" = "tours"."id" and "starts_on"::date > :'today') asc, "id" asc limit 12 offset 0;
