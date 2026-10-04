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
}
