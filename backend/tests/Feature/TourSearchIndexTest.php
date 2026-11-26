<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Tour;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Exercise 11 (D24): the public search must stay answerable from the
| trigram index. Behaviour (what a search finds) is covered in
| TourCatalogTest; these tests cover the schema and the query shape.
*/
class TourSearchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_tour_titles_have_a_trigram_gin_index(): void
    {
        $definition = DB::scalar(
            "select indexdef from pg_indexes where schemaname = 'public' and indexname = 'tours_title_trgm'"
        );

        $this->assertIsString($definition, 'The index tours_title_trgm does not exist.');
        $this->assertStringContainsString('USING gin (title gin_trgm_ops)', $definition);
    }

    /*
    | An index that exists is useless if the query shape keeps the planner
    | from using it: `title ILIKE ? OR EXISTS (cities ...)` was answered with
    | a sequential scan even with the index present (measured, D24).
    |
    | The test captures the real SQL of a real request (the paginate() COUNT
    | and the page), then asks Postgres for the plan. With a handful of test
    | rows a sequential scan is always cheapest, so switching the other scan
    | types off asks "could you use the index?" rather than "would you?".
    | SET LOCAL lasts until the end of the test's RefreshDatabase transaction.
    |
    | It asserts one structured fact from the JSON plan (an index name), not
    | the plan's text, so harmless planner changes do not break it.
    */
    public function test_the_planner_can_use_the_trigram_index_for_the_public_search(): void
    {
        Tour::factory()->withDeparture()->create(['title' => 'Walk the Qadisha valley']);

        foreach ($this->indexesUsedBySearch('qadisha', expectedResults: 1) as $sql => $indexes) {
            $this->assertContains('tours_title_trgm', $indexes, $this->cannotUse('tours_title_trgm', $indexes, $sql));
        }
    }

    /*
    | When the term also matches a city, both sides of the OR must be
    | answerable from an index (a BitmapOr); one unindexed side forces a
    | scan of the whole table.
    */
    public function test_the_planner_can_use_both_indexes_when_a_city_matches(): void
    {
        $byblos = City::factory()->create(['name' => 'Byblos']);
        Tour::factory()->for($byblos)->withDeparture()->create(['title' => 'Old souk walk']);

        foreach ($this->indexesUsedBySearch('byb', expectedResults: 1) as $sql => $indexes) {
            $this->assertContains('tours_title_trgm', $indexes, $this->cannotUse('tours_title_trgm', $indexes, $sql));
            $this->assertContains('tours_city_id_index', $indexes, $this->cannotUse('tours_city_id_index', $indexes, $sql));
        }
    }

    /**
     * Runs GET /api/tours?search=$term, then EXPLAINs the two search
     * queries on `tours` it ran (the paginate() COUNT and the page).
     *
     * @return array<string, list<string>> SQL => index names in its plan
     */
    private function indexesUsedBySearch(string $term, int $expectedResults): array
    {
        /** @var list<QueryExecuted> $queries */
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query;
        });

        $this->getJson('/api/tours?search='.urlencode($term))->assertOk()->assertJsonCount($expectedResults, 'data');

        $searches = array_filter($queries, fn (QueryExecuted $q) => str_contains($q->sql, 'from "tours"') && str_contains($q->sql, 'ilike'));
        $this->assertCount(2, $searches, 'Expected the COUNT and the page query.');

        // Only bitmap scans stay "free". A GIN index is only ever read with a
        // bitmap scan, and a bitmap scan on tours needs an indexable search
        // condition. Without the last two settings the planner may read all
        // of tours through tours_pkey (an index scan, but not of the search
        // condition): that happened in the full suite, where earlier tests
        // leave tours with different size statistics than a fresh table.
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL enable_indexscan = off');
        DB::statement('SET LOCAL enable_indexonlyscan = off');

        $indexes = [];
        foreach ($searches as $search) {
            $plan = DB::selectOne('EXPLAIN (FORMAT JSON) '.$search->sql, $search->bindings);
            $indexes[$search->sql] = $this->indexNames(json_decode($plan->{'QUERY PLAN'}, true));
        }

        return $indexes;
    }

    /** @param  list<string>  $used */
    private function cannotUse(string $index, array $used, string $sql): string
    {
        return "The planner cannot use {$index} (the plan uses: ".(implode(', ', $used) ?: 'no index').") for:\n{$sql}";
    }

    /**
     * Every "Index Name" anywhere in a JSON plan.
     *
     * @param  array<mixed>  $node
     * @return list<string>
     */
    private function indexNames(array $node): array
    {
        $names = [];
        array_walk_recursive($node, function (mixed $value, int|string $key) use (&$names) {
            if ($key === 'Index Name') {
                $names[] = $value;
            }
        });

        return $names;
    }
}
