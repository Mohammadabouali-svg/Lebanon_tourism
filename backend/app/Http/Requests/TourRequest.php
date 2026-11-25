<?php

namespace App\Http\Requests;

use App\Enums\TourStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/*
| Used for both creating and updating a tour (operator area).
|
| The request is multipart/form-data because it may carry an image. Updates
| are therefore sent as POST with `_method=PUT`: PHP only parses file
| uploads for POST requests, and Laravel treats `_method` as the real verb.
*/
class TourRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            // Optional. An empty form field arrives as null (ConvertEmptyStringsToNull).
            'meeting_point' => ['nullable', 'string', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            // $1.00 to $10,000.00 (inclusive). The cap is a business limit
            // (docs/decisions.md D17), so it lives here and not in a DB CHECK.
            'price_cents' => ['required', 'integer', 'min:100', 'max:1000000'],
            // The length of every departure (Exercise 10). Dates and capacity
            // are per departure: StoreDepartureRequest / UpdateDepartureRequest.
            // 1..30 is also a CHECK (tours_duration_range).
            'duration_days' => ['required', 'integer', 'min:1', 'max:30'],
            'status' => ['required', Rule::enum(TourStatus::class)],
            // `image` + `mimes` look at the file *content*, not its name.
            // SVG is excluded on purpose: SVG files can contain JavaScript.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=400,min_height=250'],
            'itinerary' => ['required', 'array', 'min:1', 'max:30'],
            'itinerary.*.day' => ['required', 'integer', 'min:1'],
            'itinerary.*.title' => ['required', 'string', 'max:150'],
            'itinerary.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Rules that compare several fields, run after the basic rules passed.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = (int) $this->input('duration_days');

                foreach ($this->input('itinerary', []) as $index => $item) {
                    if ((int) $item['day'] > $days) {
                        $validator->errors()->add("itinerary.{$index}.day", "Day {$item['day']} is after the end of this {$days}-day tour.");
                    }
                }
            },
        ];
    }

    /**
     * The default `max` message would print the limit in cents
     * ("must not be greater than 1000000"), which means nothing to an operator.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_cents.max' => 'The price may not be more than $10,000.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'itinerary.*.day' => 'day',
            'itinerary.*.title' => 'itinerary title',
            'price_cents' => 'price',
            'meeting_point' => 'meeting point',
            'duration_days' => 'length in days',
        ];
    }
}
