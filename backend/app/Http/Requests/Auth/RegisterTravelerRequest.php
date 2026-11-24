<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/*
| A Form Request validates input BEFORE the controller runs. If validation
| fails, Laravel answers 422 with a list of errors per field, and the
| controller is never called. The controller can then trust
| $request->validated(): it contains only the fields listed in rules().
*/
class RegisterTravelerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Normalise before the `unique` check, so "Ana@X.com" is a duplicate of "ana@x.com".
        if (is_string($this->email)) {
            $this->merge(['email' => mb_strtolower(trim($this->email))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            // Password::defaults() is configured in AppServiceProvider.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/'],
        ];
    }
}
