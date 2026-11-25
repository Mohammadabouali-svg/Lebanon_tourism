<?php

namespace App\Http\Requests;

use App\Models\Tour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/*
| Adding departures to a tour (operator area, Exercise 10).
|
| `weeks` makes a weekly series: starts_on, starts_on + 7 days, ... That is
| how "the same tour every Saturday" is entered, without storing a
| recurrence rule (D23). The series is all-or-nothing: one taken date
| refuses the whole request.
|
| authorize() runs before the rules. Checking ownership here, and not in the
| controller, matters: otherwise the "date already taken" rule below would
| tell any operator which dates another operator's tour runs on.
*/
class StoreDepartureRequest extends FormRequest
{
    public const MAX_WEEKS = 26;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tour'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d', 'after:today'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'weeks' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_WEEKS],
        ];
    }

    /** @return list<CarbonImmutable> every date of the series, first one first */
    public function dates(): array
    {
        $first = CarbonImmutable::parse($this->validated('starts_on'));

        return array_map(
            fn (int $week) => $first->addWeeks($week),
            range(0, (int) ($this->validated('weeks') ?? 1) - 1),
        );
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Tour $tour */
                $tour = $this->route('tour');
                $taken = $tour->departures()
                    ->whereIn('starts_on', array_map(fn (CarbonImmutable $date) => $date->toDateString(), $this->dates()))
                    ->pluck('starts_on');

                foreach ($taken as $date) {
                    $validator->errors()->add('starts_on', "This tour already has a departure on {$date->toDateString()}.");
                }
            },
        ];
    }
}
