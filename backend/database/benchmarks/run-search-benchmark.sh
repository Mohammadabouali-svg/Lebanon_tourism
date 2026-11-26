#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Exercise 11: runs search-explain.sql for each search term, 6 times per term,
# against lebanon_tourism_bench. Prints the full plans of the LAST run per term
# (the first run reads from disk; later runs show the warm-cache steady state)
# and a summary line with all six execution times of each query.
#
#   bash backend/database/benchmarks/run-search-benchmark.sh before 2026-11-26 > before.txt
#   bash backend/database/benchmarks/run-search-benchmark.sh after  2026-11-26 > after.txt
#
# Run from the project root, with the Docker stack up.
# -----------------------------------------------------------------------------
set -euo pipefail

phase="${1:?usage: run-search-benchmark.sh before|after YYYY-MM-DD}"
today="${2:?usage: run-search-benchmark.sh before|after YYYY-MM-DD}"
script="$(dirname "$0")/search-explain-${phase}.sql"
runs=6

# term label | pattern exactly as Search::containsPattern() builds it
terms=(
    'hike (common word, 5% of titles)|%hike%'
    'zaatar (rare word, 10 titles)|%zaatar%'
    'byb (city name only)|%byb%'
    'by (2 characters)|%by%'
    '100% (escaped wildcard)|%100\%%'
    'qqqzzz (no match)|%qqqzzz%'
)

for entry in "${terms[@]}"; do
    label="${entry%%|*}"
    pattern="${entry#*|}"
    city_times=()
    count_times=()
    page_times=()
    for run in $(seq 1 "$runs"); do
        output="$(docker compose exec -T db psql -U lebanon_tourism -d lebanon_tourism_bench \
            -v ON_ERROR_STOP=1 -v "pattern=$pattern" -v "today=$today" -f - < "$script")"
        mapfile -t times < <(grep -o 'Execution Time: [0-9.]*' <<< "$output" | awk '{print $3}')
        # AFTER has the city lookup first; the last two are always COUNT and page.
        n=${#times[@]}
        if [ "$n" -eq 3 ]; then city_times+=("${times[0]}"); fi
        count_times+=("${times[n-2]}")
        page_times+=("${times[n-1]}")
    done
    echo "=================================================================="
    echo "TERM: $label   pattern: $pattern"
    if [ "${#city_times[@]}" -gt 0 ]; then
        echo "city lookup ms (runs 1-$runs): ${city_times[*]}"
    fi
    echo "count query ms (runs 1-$runs): ${count_times[*]}"
    echo "page query  ms (runs 1-$runs): ${page_times[*]}"
    echo "------------------------------------------------------------------"
    echo "$output"
    echo
done
