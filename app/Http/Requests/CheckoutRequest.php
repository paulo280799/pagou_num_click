<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refExternal' => [
                'required',
                Rule::unique('payments', 'refExternal')->where(fn ($query) =>
                    $query->where('account_id', auth()->user()->account->id)
                )
            ],
            'items' => 'required|array|min:1',
            'items.*.amount' => [
                'required',
                'regex:/^\d+(\.\d{1,2})?$/', // Valida que o valor tem no máximo duas casas decimais
            ],
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',

            'customer' => 'required|array',
            'customer.name' => 'required|string',
            'customer.email' => 'required|email',
            'customer.type' => 'required|in:individual,corporation',
            'customer.document' => 'required|string',

            'customer.phones' => 'required|array',
            'customer.phones.home_phone' => 'required|array',
            'customer.phones.home_phone.country_code' => 'required|string',
            'customer.phones.home_phone.area_code' => 'required|string',
            'customer.phones.home_phone.number' => 'required|string',

            // payments removido da validação — será preenchido automaticamente
        ];
    }
}
