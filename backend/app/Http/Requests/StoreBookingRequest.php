<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/*
| Notice what is NOT here: no price, no total, no "available spots".
| The client says *what* it wants (tour, spots, payment method, code); the
| server decides what it costs. Any extra field (e.g. "price": 0) is simply
| ignored, because controllers only read $request->validated().
*/
class StoreBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // min:1 rejects 0 and negative numbers. In the original project a
            // negative number of spots *added* money to the wallet.
            'spots' => ['required', 'integer', 'min:1', 'max:20'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'discount_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
