<?php

namespace App\Http\Requests;

use App\Models\Config;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Gateway com que o browser tokenizou; ausente = Pagar.me (clientes anteriores ao fallback). */
    public function gateway(): string
    {
        return $this->input('gateway', 'pagarme');
    }

    public function rules(): array
    {
        // limite vem do snapshot do Payment (Config da conta na criação); id inexistente cai no 404 do controller
        $payment = Payment::withoutGlobalScopes()->find($this->route('id'));
        $maxInstallments = $payment?->installmentLimit() ?? Config::MAX_INSTALLMENTS;

        $rules = [
            'gateway' => ['sometimes', 'string', Rule::in(['pagarme', 'mercadopago'])],
        ];

        if ($this->gateway() === 'mercadopago') {
            // token do MP: hexadecimal, sem endereço de cobrança; só 1x até o spike de parcelamento (S4)
            return $rules + [
                'card_token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
                'payment_method_id' => ['required', 'string', 'regex:/^[a-z0-9_]{2,32}$/'],
                'issuer_id' => ['nullable', 'regex:/^[0-9]{1,12}$/'],
                'device_id' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9._-]+$/'],
                'installments' => ['required', 'integer', 'min:1', 'max:1'],
            ];
        }

        return $rules + [
            'card_token' => ['required', 'string', 'regex:/^token_[A-Za-z0-9]+$/'],
            'installments' => ['required', 'integer', 'min:1', 'max:'.$maxInstallments],
            'billing_address.line_1' => ['required', 'string', 'max:256'],
            'billing_address.zip_code' => ['required', 'string', 'max:16'],
            'billing_address.city' => ['required', 'string', 'max:64'],
            'billing_address.state' => ['required', 'string', 'max:64'],
            'billing_address.country' => ['required', 'string', 'size:2'],
        ];
    }
}
