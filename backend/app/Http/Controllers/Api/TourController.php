<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TourIndexRequest;
use App\Http\Resources\TourResource;
use App\Models\Review;
use App\Models\Tour;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The public catalogue. */
class TourController extends Controller
{
    /*
    | GET /api/tours?search=&region_id=&city_id=&category_id=&max_price_cents=
    |               &min_days=&max_days=&min_rating=&sort=&page=&per_page=
    |
    | Each filter is optional and added with ->when(): when the value is
    | present, the closure adds its condition; otherwise nothing happens.
    | Every value is bound as a query parameter, never glued into SQL.
    */
    public function index(TourIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $tours = Tour::query()
            ->visibleToPublic()
            ->upcoming()
            // Eager loading: 4 queries in total, whatever the page size.
            // Without with(), every tour would trigger 3 more queries ("N+1").
            ->with(['city.region', 'category', 'operator.operatorProfile'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->when($filters['search'] ?? null, function (Builder $query, string $term) {
                $pattern = Search::containsPattern($term);
                $query->where(fn (Builder $q) => $q
                    ->whereLike('title', $pattern)
                    ->orWhereHas('city', fn (Builder $city) => $city->whereLike('name', $pattern)));
            })
            ->when($filters['region_id'] ?? null, fn (Builder $query, int $regionId) => $query
                ->whereHas('city', fn (Builder $city) => $city->where('region_id', $regionId)))
            ->when($filters['city_id'] ?? null, fn (Builder $query, int $cityId) => $query->where('city_id', $cityId))
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['max_price_cents'] ?? null, fn (Builder $query, int $max) => $query->where('price_cents', '<=', $max))
            ->when($filters['min_days'] ?? null, fn (Builder $query, int $min) => $query->where('duration_days', '>=', $min))
            ->when($filters['max_days'] ?? null, fn (Builder $query, int $max) => $query->where('duration_days', '<=', $max))
            ->when($filters['min_rating'] ?? null, fn (Builder $query, $min) => $query->where(
                // A correlated subquery: the average rating of *this* tour.
                fn ($sub) => $sub->from((new Review)->getTable())->selectRaw('AVG(rating)')->whereColumn('reviews.tour_id', 'tours.id'),
                '>=',
                (float) $min,
            ));

        match ($filters['sort'] ?? 'soonest') {
            'price_asc' => $tours->orderBy('price_cents'),
            'price_desc' => $tours->orderByDesc('price_cents'),
            'rating' => $tours->orderByRaw('reviews_avg_rating DESC NULLS LAST'),
            default => $tours->orderBy('starts_on'),
        };

        // A unique tie-breaker keeps pagination stable: without it, two tours
        // with the same price could swap places between page 1 and page 2.
        $tours->orderBy('id');

        return TourResource::collection($tours->paginate($filters['per_page'] ?? 12)->withQueryString());
    }

    public function show(int $tour): TourResource
    {
        $tour = Tour::query()
            ->visibleToPublic()
            ->with(['city.region', 'category', 'operator.operatorProfile', 'itineraryItems'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->findOrFail($tour);

        return TourResource::make($tour);
    }
}
