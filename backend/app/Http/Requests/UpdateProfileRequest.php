<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/*
| Only name and phone can be changed here. Changing the e-mail address would
| require verifying the new address again; that flow is left as an exercise
| (see docs/10-exercises.md).
*/
class UpdateProfileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/'],
        ];
    }
}
