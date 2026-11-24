<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/*
| Filters for the public tour list, e.g. GET /api/tours?city_id=3&max_days=3
|
| Query-string values arrive as strings. Validating them as integers here
| means the query code below can rely on proper types.
*/
class TourIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'region_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'max_price_cents' => ['nullable', 'integer', 'min:0'],
            'min_days' => ['nullable', 'integer', 'min:1'],
            'max_days' => ['nullable', 'integer', 'min:1'],
            'min_rating' => ['nullable', 'numeric', 'between:1,5'],
            'sort' => ['nullable', 'in:soonest,price_asc,price_desc,rating'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ];
    }
}
