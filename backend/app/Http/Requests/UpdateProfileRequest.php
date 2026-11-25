<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/*
| Only name and phone can be changed here. The e-mail address has its own
| flow (EmailChangeController, D20): the new address must be confirmed from
| its inbox before it replaces the old one, so it is never a plain update.
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
