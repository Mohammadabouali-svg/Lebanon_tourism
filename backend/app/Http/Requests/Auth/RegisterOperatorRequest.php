<?php

namespace App\Http\Requests\Auth;

/*
| An operator registers with the same personal fields as a traveler, plus
| company details. Extending the traveler request avoids duplicating rules.
*/
class RegisterOperatorRequest extends RegisterTravelerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'company_name' => ['required', 'string', 'max:150', 'unique:operator_profiles,company_name'],
            'business_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/'],
        ];
    }
}
