<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreditWalletRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Between $1 and $10,000 per operation: a typo such as an extra
            // zero is caught instead of silently creating a fortune.
            'amount_cents' => ['required', 'integer', 'min:100', 'max:1000000'],
            // Required, so the ledger always says why money was added.
            'description' => ['required', 'string', 'max:255'],
        ];
    }
}
