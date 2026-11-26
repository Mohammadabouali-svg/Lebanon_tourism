-- ---------------------------------------------------------------------------
-- Exercise 11: 100,000 generated tours for measuring the public search.
--
-- Run it ONLY against a throwaway database (lebanon_tourism_bench), after
-- `php artisan migrate` + `db:seed --class=CatalogSeeder` there:
--
--   docker compose exec -T db psql -U lebanon_tourism -d lebanon_tourism_bench \
--       -v ON_ERROR_STOP=1 -f - < backend/database/benchmarks/search-100k.sql
--
-- It expects empty users/tours/departures tables with fresh id sequences
-- (tour id = row number). To reload, first:
--   TRUNCATE users, tours, departures RESTART IDENTITY CASCADE;
--
-- Why SQL and not factories: generate_series inserts 100,000 rows in seconds;
-- 100,000 Eloquent factory calls take a very long time on this bind mount.
-- Everything is derived from the row number (no random()), so every run
-- produces exactly the same data and the before/after numbers compare.
--
-- Shape, chosen to look like a real catalogue for the search query:
--   * 200 operators, 90% approved (visibleToPublic() must filter the rest)
--   * titles = adjective + activity + "through the" + scenery, 20 words each,
--     so one activity word ("Hike") is in 5% of titles (a common term)
--   * 10 titles contain "Zaatar" (a rare term)
--   * no generated title contains a city name: "byb" only matches via the city
--   * 90% published, 5% draft, 5% archived
--   * 1-4 weekly departures; 15% of tours have only past dates, so
--     upcoming() really removes rows
-- ---------------------------------------------------------------------------

BEGIN;

INSERT INTO users (name, email, password, role, email_verified_at, created_at, updated_at)
SELECT 'Bench operator ' || g, 'bench-operator-' || g || '@example.com',
       'not-a-real-hash', 'operator', now(), now(), now()
FROM generate_series(1, 200) AS g;

INSERT INTO operator_profiles (user_id, company_name, business_phone, status, approved_at, created_at, updated_at)
SELECT u.id, 'Bench company ' || u.id, '+961 1 000000',
       CASE WHEN u.id % 10 = 0 THEN 'pending' ELSE 'approved' END,
       CASE WHEN u.id % 10 = 0 THEN NULL ELSE now() END,
       now(), now()
FROM users AS u
WHERE u.email LIKE 'bench-operator-%';

WITH
    operators AS (SELECT array_agg(id ORDER BY id) AS ids FROM users WHERE email LIKE 'bench-operator-%'),
    cities AS (SELECT array_agg(id ORDER BY id) AS ids FROM cities),
    categories AS (SELECT array_agg(id ORDER BY id) AS ids FROM categories),
    words AS (
        SELECT ARRAY['Sunset', 'Hidden', 'Ancient', 'Golden', 'Quiet', 'Wild', 'Classic', 'Secret',
                     'Grand', 'Gentle', 'Misty', 'Rugged', 'Sunny', 'Royal', 'Rustic', 'Coastal',
                     'Silent', 'Bright', 'Lazy', 'Epic'] AS adjectives,
               ARRAY['Hike', 'Walk', 'Ride', 'Tasting', 'Picnic', 'Cruise', 'Climb', 'Escape',
                     'Journey', 'Trail', 'Safari', 'Retreat', 'Discovery', 'Ramble', 'Trek',
                     'Wander', 'Outing', 'Getaway', 'Expedition', 'Visit'] AS activities,
               ARRAY['Cedars', 'Valleys', 'Vineyards', 'Ruins', 'Caves', 'Hills', 'Gardens',
                     'Monasteries', 'Souks', 'Orchards', 'Rivers', 'Waterfalls', 'Castles',
                     'Beaches', 'Forests', 'Villages', 'Terraces', 'Springs', 'Peaks',
                     'Harbours'] AS scenery
    )
INSERT INTO tours (operator_id, city_id, category_id, title, description, price_cents,
                   status, duration_days, created_at, updated_at)
SELECT o.ids[1 + g % array_length(o.ids, 1)],
       c.ids[1 + (g * 7) % array_length(c.ids, 1)],
       k.ids[1 + (g * 3) % array_length(k.ids, 1)],
       w.adjectives[1 + g % 20] || ' '
           || w.activities[1 + (g / 20) % 20]
           || ' through the ' || w.scenery[1 + (g / 400) % 20]
           -- 57: published (57 % 20), approved operator (id 58), upcoming dates (57 % 100 >= 15).
           || CASE WHEN g % 10000 = 57 THEN ' with Zaatar' ELSE '' END,
       'Generated benchmark tour number ' || g || '.',
       2000 + (g % 50) * 1000,
       CASE WHEN g % 20 = 0 THEN 'draft' WHEN g % 20 = 1 THEN 'archived' ELSE 'published' END,
       1 + g % 7,
       now(), now()
FROM generate_series(1, 100000) AS g, operators AS o, cities AS c, categories AS k, words AS w;

-- Weekly dates. Tours with id % 100 < 15 only have dates in the past.
INSERT INTO departures (tour_id, starts_on, capacity, booked_spots, created_at, updated_at)
SELECT t.id,
       CASE WHEN t.id % 100 < 15 THEN current_date - (7 * n + 1)::int
            ELSE current_date + (7 * n + t.id % 7)::int END,
       12, 0, now(), now()
FROM tours AS t
CROSS JOIN LATERAL generate_series(1, 1 + t.id % 4) AS n;

COMMIT;

-- Fresh statistics, so the planner sees 100,000 rows and not an empty table.
ANALYZE;

SELECT (SELECT count(*) FROM tours) AS tours,
       (SELECT count(*) FROM departures) AS departures,
       (SELECT count(*) FROM tours WHERE title ILIKE '%hike%') AS hike_titles,
       (SELECT count(*) FROM tours WHERE title ILIKE '%zaatar%') AS zaatar_titles;
