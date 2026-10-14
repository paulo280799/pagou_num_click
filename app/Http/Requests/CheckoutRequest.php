<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public const METHODS = ['pix', 'credit_card'];

    public function authorize(): bool
    {
        return true;
    }

    /** API pública: erro de validação é sempre JSON 422, mesmo sem `Accept: application/json`. */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $extra = $errors->count() - 1;

        throw new HttpResponseException(response()->json([
            'message' => $errors->first().($extra > 0 ? " (and {$extra} more ".($extra === 1 ? 'error' : 'errors').')' : ''),
            'errors' => $errors->messages(),
        ], 422));
    }

    public function rules(): array
    {
        return [
            'refExternal' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payments', 'refExternal')->where(fn ($query) =>
                    $query->where('account_id', auth()->user()->account->id)
                )
            ],

            // meios de pagamento do checkout; omitido = ['pix', 'credit_card']
            'payment_methods' => ['sometimes', 'array', 'min:1', 'max:'.count(self::METHODS)],
            'payment_methods.*' => ['string', 'distinct', Rule::in(self::METHODS)],

            'items' => 'required|array|min:1|max:50',
            'items.*.amount' => [
                'bail',
                'required',
                'regex:/^\d{1,9}(\.\d{1,2})?$/', // reais, ponto decimal, até 2 casas
                'numeric',
                'min:0.01',
            ],
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:9999',

            'customer' => 'required|array',
            'customer.name' => 'required|string|max:64',
            'customer.email' => 'required|email|max:64',
            'customer.type' => 'required|in:individual,corporation',
            'customer.document' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
                $digits = preg_replace('/\D/', '', (string) $value);
                $expected = $this->input('customer.type') === 'corporation' ? 14 : 11;

                if (strlen($digits) !== $expected) {
                    $fail($expected === 14
                        ? 'O CNPJ deve ter 14 dígitos (customer.type = corporation).'
                        : 'O CPF deve ter 11 dígitos (customer.type = individual).');
                }
            }],

            'customer.phones' => 'required|array',
            'customer.phones.home_phone' => 'required|array',
            'customer.phones.home_phone.country_code' => ['required', 'string', 'regex:/^\d{1,3}$/'],
            'customer.phones.home_phone.area_code' => ['required', 'string', 'regex:/^\d{2,3}$/'],
            'customer.phones.home_phone.number' => ['required', 'string', 'regex:/^\d{8,9}$/'],

            // payments removido da validação — será preenchido automaticamente
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.amount.regex' => 'O campo :attribute deve ser um valor em reais com ponto decimal e até 2 casas (ex.: 10.50).',
            'customer.phones.home_phone.country_code.regex' => 'O DDI deve ter de 1 a 3 dígitos, sem "+" (ex.: 55).',
            'customer.phones.home_phone.area_code.regex' => 'O DDD deve ter 2 ou 3 dígitos (ex.: 11).',
            'customer.phones.home_phone.number.regex' => 'O número deve ter 8 ou 9 dígitos, sem DDD (ex.: 999999999).',
        ];
    }
}
