<?php

namespace App\Http\Requests;

use App\Models\Departure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/*
| Changing one departure's date or capacity (operator area, Exercise 10).
| Rules that depend on its bookings (capacity below booked spots, moving a
| booked date, a started departure) need the locked row, so they live in
| DepartureService, not here.
|
| authorize() runs before the rules, for the reason given in
| StoreDepartureRequest.
*/
class UpdateDepartureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('departure'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Departure $departure */
        $departure = $this->route('departure');

        return [
            'starts_on' => [
                'required', 'date_format:Y-m-d', 'after:today',
                Rule::unique('departures', 'starts_on')->where('tour_id', $departure->tour_id)->ignore($departure->id),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_on.unique' => 'This tour already has a departure on that date.',
        ];
    }
}
