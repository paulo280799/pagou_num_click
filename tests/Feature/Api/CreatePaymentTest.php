<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'refExternal' => 'ref-'.uniqid(),
            'items' => [['amount' => '10.50', 'description' => 'Camiseta', 'quantity' => 1]],
            'customer' => [
                'name' => 'Fulano',
                'email' => 'fulano@example.test',
                'type' => 'individual',
                'document' => '123.456.789-00',
                'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '999999999']],
            ],
        ], $overrides);
    }

    private function fakeAccountWithToken(string $token = 'valid-token'): Account
    {
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $account->id, 'api_token' => $token, 'duration' => '900']);

        return $account;
    }

    private function fakeProviderOrderResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'order_1',
            'amount' => 1050,
            'status' => 'pending',
            'charges' => [[
                'payment_method' => 'pix',
                'last_transaction' => ['qr_code_url' => 'https://qr.test/img.png', 'qr_code' => '00020126...'],
            ]],
        ], $overrides);
    }

    public function test_creates_payment_and_returns_checkout_link_on_provider_success(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $response = $this->postJson('/api/create-payment', $this->validPayload(), [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(201)->assertJsonStructure(['payment_link']);

        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertNotNull($payment);
        $this->assertSame('order_1', $payment->ide);
        $this->assertSame(10.50, $payment->amount);
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
        $this->assertSame(PaymentMethodEnum::PIX, $payment->payment_method);
        $this->assertStringContainsString("/checkout/{$payment->id}", $response->json('payment_link'));
    }

    public function test_returns_provider_error_when_order_creation_fails(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response(['message' => 'invalid request'], 422)]);

        $response = $this->postJson('/api/create-payment', $this->validPayload(), [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_failed_charge_with_http_200_returns_422_and_creates_no_payment(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response([
            'id' => 'order_failed',
            'status' => 'failed',
            'charges' => [[
                'status' => 'failed',
                'payment_method' => 'pix',
                'last_transaction' => ['gateway_response' => ['errors' => [['message' => 'action_forbidden |  | Erro no gateway']]]],
            ]],
        ], 200)]);

        $response = $this->postJson('/api/create-payment', $this->validPayload(), [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_payment_stores_encrypted_customer_with_unmasked_document(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $this->postJson('/api/create-payment', $this->validPayload(), [
            'Authorization' => 'Bearer valid-token',
        ])->assertStatus(201)->assertExactJson(['payment_link' => config('app.url').'/checkout/'.Payment::withoutGlobalScopes()->first()->id]);

        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertSame('12345678900', $payment->customer['document']);
        $this->assertSame('fulano@example.test', $payment->customer['email']);
    }

    public function test_unknown_status_from_provider_falls_back_to_pending(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response(
            $this->fakeProviderOrderResponse(['status' => 'something_unmapped']),
            200
        )]);

        $response = $this->postJson('/api/create-payment', $this->validPayload(), [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(201);
        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
    }

    public function test_unknown_payment_method_from_provider_falls_back_to_pix(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response(
            $this->fakeProviderOrderResponse(['charges' => [[
                'payment_method' => 'boleto',
                'last_transaction' => ['qr_code_url' => 'https://qr.test/img.png', 'qr_code' => '00020126...'],
            ]]]),
            200
        )]);

        $response = $this->postJson('/api/create-payment', $this->validPayload(), [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(201);
        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertSame(PaymentMethodEnum::PIX, $payment->payment_method);
    }

    public function test_duplicate_ref_external_for_same_account_is_rejected(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $payload = $this->validPayload(['refExternal' => 'duplicate-ref']);

        $this->postJson('/api/create-payment', $payload, ['Authorization' => 'Bearer valid-token'])
            ->assertStatus(201);

        $response = $this->postJson('/api/create-payment', $payload, ['Authorization' => 'Bearer valid-token']);

        $response->assertStatus(422);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('refExternal', 'duplicate-ref')->count());
    }

    public function test_same_ref_external_is_allowed_across_different_accounts(): void
    {
        $this->fakeAccountWithToken('token-a');
        $this->fakeAccountWithToken('token-b');
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $payload = $this->validPayload(['refExternal' => 'shared-ref']);

        $this->postJson('/api/create-payment', $payload, ['Authorization' => 'Bearer token-a'])
            ->assertStatus(201);
        $this->postJson('/api/create-payment', $payload, ['Authorization' => 'Bearer token-b'])
            ->assertStatus(201);

        $this->assertSame(2, Payment::withoutGlobalScopes()->where('refExternal', 'shared-ref')->count());
    }

    /** @dataProvider invalidPayloads */
    public function test_validation_rejects_invalid_payloads(array $overrides, string $invalidField): void
    {
        $this->fakeAccountWithToken();

        $response = $this->postJson('/api/create-payment', $this->validPayload($overrides), [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([$invalidField]);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing refExternal' => [['refExternal' => null], 'refExternal'],
            'amount with too many decimals' => [
                ['items' => [['amount' => '10.555', 'description' => 'x', 'quantity' => 1]]],
                'items.0.amount',
            ],
            'amount with letters' => [
                ['items' => [['amount' => 'abc', 'description' => 'x', 'quantity' => 1]]],
                'items.0.amount',
            ],
            'missing items' => [['items' => []], 'items'],
            'invalid customer type' => [
                ['customer' => [
                    'name' => 'Fulano', 'email' => 'a@a.com', 'type' => 'invalid',
                    'document' => '123', 'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '1']],
                ]],
                'customer.type',
            ],
            'invalid email' => [
                ['customer' => [
                    'name' => 'Fulano', 'email' => 'not-an-email', 'type' => 'individual',
                    'document' => '123', 'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '1']],
                ]],
                'customer.email',
            ],
        ];
    }

    public function test_cpf_mask_is_stripped_before_sending_to_provider(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $this->postJson('/api/create-payment', $this->validPayload([
            'customer' => [
                'name' => 'Fulano', 'email' => 'a@a.com', 'type' => 'individual',
                'document' => '123.456.789-00',
                'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '999999999']],
            ],
        ]), ['Authorization' => 'Bearer valid-token'])->assertStatus(201);

        Http::assertSent(function ($request) {
            return $request['customer']['document'] === '12345678900';
        });
    }

    public function test_amount_is_converted_to_centavos_in_provider_request(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $this->postJson('/api/create-payment', $this->validPayload([
            'items' => [['amount' => '10.50', 'description' => 'Camiseta', 'quantity' => 1]],
        ]), ['Authorization' => 'Bearer valid-token'])->assertStatus(201);

        Http::assertSent(function ($request) {
            return $request['items'][0]['amount'] === 1050
                && $request['payments'][0]['amount'] === 1050;
        });
    }

    private function createPayment(array $overrides = [])
    {
        return $this->postJson('/api/create-payment', $this->validPayload($overrides), ['Authorization' => 'Bearer valid-token']);
    }

    public function test_payment_methods_defaults_to_pix_and_card(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $this->createPayment()->assertStatus(201);

        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertSame(['pix', 'credit_card'], $payment->payment_methods);
    }

    public function test_pix_only_creates_provider_order_and_disables_card(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $this->createPayment(['payment_methods' => ['pix']])->assertStatus(201);

        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertSame(['pix'], $payment->payment_methods);
        $this->assertFalse($payment->allowsMethod('credit_card'));
        $this->assertSame('order_1', $payment->ide);
    }

    public function test_card_only_creates_no_provider_order_and_no_pix_data(): void
    {
        config(['integration.public_key' => 'pk_test_abc123']);
        $this->fakeAccountWithToken();
        Http::fake();

        $this->createPayment(['payment_methods' => ['credit_card']])->assertStatus(201)->assertJsonStructure(['payment_link']);

        Http::assertNothingSent();
        $payment = Payment::withoutGlobalScopes()->first();
        $this->assertSame(['credit_card'], $payment->payment_methods);
        $this->assertNull($payment->ide);
        $this->assertNull($payment->qrCode);
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
        $this->assertSame(PaymentMethodEnum::CREDIT_CARD, $payment->payment_method);
        $this->assertSame(10.5, $payment->amount);
        $this->assertSame('12345678900', $payment->customer['document']);
    }

    public function test_card_explicitly_requested_without_public_key_is_rejected(): void
    {
        config(['integration.public_key' => null]);
        $this->fakeAccountWithToken();
        Http::fake();

        $this->createPayment(['payment_methods' => ['credit_card']])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('errors.payment_methods.0', 'O meio credit_card não está disponível.');

        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    /** @dataProvider invalidPaymentMethods */
    public function test_invalid_payment_methods_are_rejected(mixed $methods): void
    {
        $this->fakeAccountWithToken();

        $this->createPayment(['payment_methods' => $methods])->assertStatus(422)->assertJsonValidationErrors('payment_methods');
    }

    public static function invalidPaymentMethods(): array
    {
        return [
            'vazio' => [[]],
            'string' => ['pix'],
        ];
    }

    public function test_unknown_or_duplicated_payment_method_is_rejected(): void
    {
        $this->fakeAccountWithToken();

        $this->createPayment(['payment_methods' => ['boleto']])->assertStatus(422)->assertJsonValidationErrors('payment_methods.0');
        $this->createPayment(['payment_methods' => ['pix', 'pix']])->assertStatus(422)->assertJsonValidationErrors('payment_methods.0');
    }

    public function test_multiple_items_and_quantity_are_sent_and_summed(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(['amount' => 22550]), 200)]);

        $this->createPayment(['items' => [
            ['amount' => '100.00', 'description' => 'Camiseta', 'quantity' => 2],
            ['amount' => '25.50', 'description' => 'Frete', 'quantity' => 1],
        ]])->assertStatus(201);

        Http::assertSent(fn ($r) => count($r['items']) === 2
            && $r['items'][0]['quantity'] === 2
            && $r['items'][0]['amount'] === 10000
            && $r['payments'][0]['amount'] === 22550);
    }

    public function test_validation_errors_are_json_without_accept_header(): void
    {
        $this->fakeAccountWithToken();

        $this->call('POST', '/api/create-payment', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer valid-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refExternal');
    }

    /** @dataProvider strictFieldRules */
    public function test_stricter_validation_rules(array $overrides, string $invalidField): void
    {
        $this->fakeAccountWithToken();

        $this->createPayment($overrides)->assertStatus(422)->assertJsonValidationErrors($invalidField);
    }

    public static function strictFieldRules(): array
    {
        $customer = fn (array $c = [], array $phone = []) => ['customer' => array_replace_recursive([
            'name' => 'Fulano', 'email' => 'a@a.com', 'type' => 'individual', 'document' => '12345678909',
            'phones' => ['home_phone' => array_merge(['country_code' => '55', 'area_code' => '11', 'number' => '999999999'], $phone)],
        ], $c)];

        return [
            'amount zero' => [['items' => [['amount' => '0.00', 'description' => 'x', 'quantity' => 1]]], 'items.0.amount'],
            'amount virgula' => [['items' => [['amount' => '10,50', 'description' => 'x', 'quantity' => 1]]], 'items.0.amount'],
            'amount negativo' => [['items' => [['amount' => '-5', 'description' => 'x', 'quantity' => 1]]], 'items.0.amount'],
            'quantity zero' => [['items' => [['amount' => '5', 'description' => 'x', 'quantity' => 0]]], 'items.0.quantity'],
            'description longa' => [['items' => [['amount' => '5', 'description' => str_repeat('a', 256), 'quantity' => 1]]], 'items.0.description'],
            'refExternal longo' => [['refExternal' => str_repeat('a', 256)], 'refExternal'],
            'cpf curto' => [$customer(['document' => '123']), 'customer.document'],
            'cnpj para individual' => [$customer(['document' => '12.345.678/0001-95']), 'customer.document'],
            'cpf para corporation' => [$customer(['type' => 'corporation']), 'customer.document'],
            'ddi com +' => [$customer([], ['country_code' => '+55']), 'customer.phones.home_phone.country_code'],
            'ddd longo' => [$customer([], ['area_code' => '0011']), 'customer.phones.home_phone.area_code'],
            'numero com mascara' => [$customer([], ['number' => '99999-9999']), 'customer.phones.home_phone.number'],
            'nome longo' => [$customer(['name' => str_repeat('a', 65)]), 'customer.name'],
        ];
    }

    public function test_corporation_with_cnpj_is_accepted(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->fakeProviderOrderResponse(), 200)]);

        $this->createPayment(['customer' => [
            'name' => 'Empresa', 'email' => 'e@e.com', 'type' => 'corporation', 'document' => '12.345.678/0001-95',
            'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '21', 'number' => '33334444']],
        ]])->assertStatus(201);
    }
}
