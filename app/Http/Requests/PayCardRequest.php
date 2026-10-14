<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'card_token' => ['required', 'string', 'regex:/^token_[A-Za-z0-9]+$/'],
            'installments' => ['required', 'integer', 'min:1', 'max:'.config('integration.card_max_installments')],
            'billing_address.line_1' => ['required', 'string', 'max:256'],
            'billing_address.zip_code' => ['required', 'string', 'max:16'],
            'billing_address.city' => ['required', 'string', 'max:64'],
            'billing_address.state' => ['required', 'string', 'max:64'],
            'billing_address.country' => ['required', 'string', 'size:2'],
        ];
    }
}
