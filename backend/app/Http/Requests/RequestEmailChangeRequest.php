<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/*
| Asking for a new e-mail address (D20). The address is only *stored as
| pending* here; EmailChangeService decides the rest.
|
| - Lower-cased before validation, like sign-up, so `unique` and the
|   "same as now" check see the form that will be stored.
| - `unique:users,email` here is a friendly early answer. It reveals no more
|   than the public sign-up form already does, and this endpoint also needs
|   a login, the password and a rate limit. The check that really protects
|   the data runs again at swap time.
| - `current_password`: a stolen session alone must not be enough to move
|   the account to another inbox (same precedent as UpdatePasswordRequest).
*/
class RequestEmailChangeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => mb_strtolower(trim($this->email))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::notIn([$this->user()->email]),
                'unique:users,email',
            ],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.not_in' => 'This is already your e-mail address.',
        ];
    }
}
