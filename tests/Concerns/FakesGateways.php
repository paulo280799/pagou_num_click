<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Fixtures e atalhos dos testes de gateway: tokens e IDs falsos, nunca número de cartão. */
trait FakesGateways
{
    protected function enableMercadoPago(): void
    {
        config([
            'gateways.mercadopago.enabled' => true,
            'gateways.mercadopago.access_token' => 'TEST-mp-token',
            'gateways.mercadopago.public_key' => 'TEST-mp-public',
            'gateways.mercadopago.url' => 'https://api.mercadopago.test',
        ]);
        Cache::flush();
    }

    protected function fakeAccountWithToken(string $token = 'valid-token', string $duration = '900'): Account
    {
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $account->id, 'api_token' => $token, 'duration' => $duration]);

        return $account;
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'refExternal' => 'ref-'.uniqid(),
            'items' => [['amount' => '10.50', 'description' => 'Camiseta', 'quantity' => 1]],
            'customer' => [
                'name' => 'Fulano de Tal',
                'email' => 'fulano@example.test',
                'type' => 'individual',
                'document' => '123.456.789-09',
                'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '999999999']],
            ],
        ], $overrides);
    }

    protected function createPayment(array $overrides = [])
    {
        return $this->postJson('/api/create-payment', $this->validPayload($overrides), ['Authorization' => 'Bearer valid-token']);
    }

    protected function pagarmePix(array $overrides = []): array
    {
        return array_merge([
            'id' => 'order_1',
            'amount' => 1050,
            'status' => 'pending',
            'charges' => [[
                'payment_method' => 'pix',
                'last_transaction' => ['qr_code_url' => 'https://qr.test/img.png', 'qr_code' => '00020126PAGARME'],
            ]],
        ], $overrides);
    }

    protected function mpPix(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ORD00000000000000000000123',
            'type' => 'online',
            'status' => 'action_required',
            'status_detail' => 'waiting_transfer',
            'total_amount' => '10.50',
            'transactions' => ['payments' => [[
                'id' => 'PAY00000000000000000000123',
                'status' => 'action_required',
                'status_detail' => 'waiting_transfer',
                'payment_method' => [
                    'id' => 'pix', 'type' => 'bank_transfer',
                    'qr_code' => '00020126MERCADOPAGO',
                    'qr_code_base64' => 'iVBORw0KGgo=',
                ],
            ]]],
        ], $overrides);
    }

    /** Ordem de cartão. $status = status da ORDEM (processed|failed|action_required|...). */
    protected function mpCard(string $status, string $detail = 'accredited', array $overrides = []): array
    {
        return array_merge([
            'id' => 'ORD00000000000000000000987',
            'type' => 'online',
            'status' => $status,
            'status_detail' => $detail,
            'total_amount' => '10.50',
            'total_paid_amount' => $status === 'processed' ? '10.50' : '0.00',
            'transactions' => ['payments' => [[
                'id' => 'PAY00000000000000000000987',
                'status' => $status,
                'status_detail' => $detail,
                'payment_method' => ['id' => 'visa', 'type' => 'credit_card', 'installments' => 1],
            ]]],
        ], $overrides);
    }

    protected function pagarmeCard(string $txStatus, string $chargeStatus = 'paid'): array
    {
        return [
            'id' => 'or_card1',
            'status' => $chargeStatus,
            'charges' => [[
                'status' => $chargeStatus,
                'last_transaction' => [
                    'status' => $txStatus, 'installments' => 1,
                    'card' => ['brand' => 'Visa', 'last_four_digits' => '1111'],
                ],
            ]],
        ];
    }

    protected function connectionFails(): \Closure
    {
        return fn () => throw new ConnectionException('timeout');
    }

    /** Pagar.me fora do ar (conexão) e MP respondendo o PIX. */
    protected function pagarmeDownMercadoPagoUp(): void
    {
        Http::fake([
            '*/v5/orders*' => $this->connectionFails(),
            '*mercadopago.test/v1/orders' => Http::response($this->mpPix(), 201),
        ]);
    }

    protected function onlyPayment(): Payment
    {
        return Payment::withoutGlobalScopes()->firstOrFail();
    }
}
