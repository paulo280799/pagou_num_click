<?php

namespace Tests\Feature\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\GatewayCredential;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

/** O webhook do MP é validado com o segredo da conta da URL e só reavalia pagamentos dela. */
class MercadoPagoWebhookTenantTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private const ORDER = 'ORDTST00000000000000000AAA';

    private Account $a;

    private Account $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateways.env_credentials_account' => null, 'gateways.mercadopago.url' => 'https://api.mercadopago.test']);
        $this->a = $this->account('segredo-A');
        $this->b = $this->account('segredo-B');
    }

    private function account(string $secret): Account
    {
        $account = Account::factory()->create();
        GatewayCredential::withoutGlobalScopes()->create([
            'account_id' => $account->id, 'gateway' => GatewayEnum::MERCADOPAGO,
            'credentials' => ['access_token' => 'TEST-'.$secret, 'webhook_secret' => $secret], 'enabled' => true,
        ]);

        return $account;
    }

    private function pending(Account $account, string $order = self::ORDER): Payment
    {
        return Payment::factory()->pending()->create(['account_id' => $account->id, 'ide' => $order, 'pix_gateway' => GatewayEnum::MERCADOPAGO, 'amount' => 100]);
    }

    private function notify(string $url, string $secret, string $id = self::ORDER)
    {
        $ts = '1700000000';
        $manifest = 'id:'.strtolower($id).";request-id:req-1;ts:{$ts};";

        return $this->postJson($url, ['type' => 'order', 'data' => ['id' => $id]], [
            'x-request-id' => 'req-1',
            'x-signature' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, $secret),
        ]);
    }

    public function test_signature_is_checked_with_the_secret_of_the_account_in_the_url(): void
    {
        $payment = $this->pending($this->a);
        Http::fake(['*mercadopago.test/v1/orders/*' => Http::response($this->mpPix(['status' => 'processed', 'status_detail' => 'accredited']), 200)]);

        $this->notify("/api/webhooks/mercadopago/{$this->a->id}", 'segredo-A')->assertOk();

        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }

    public function test_another_accounts_secret_is_rejected(): void
    {
        $payment = $this->pending($this->a);
        Http::fake();

        $this->notify("/api/webhooks/mercadopago/{$this->a->id}", 'segredo-B')->assertStatus(401);

        Http::assertNothingSent();
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_a_valid_notification_never_touches_payments_of_other_accounts(): void
    {
        $other = $this->pending($this->b);
        Http::fake();

        // assinatura válida da conta A, mas o pedido é da conta B
        $this->notify("/api/webhooks/mercadopago/{$this->a->id}", 'segredo-A')->assertOk();

        Http::assertNothingSent();
        $this->assertSame(StatusPaymentEnum::PENDING, $other->refresh()->status);
    }

    public function test_account_without_mercado_pago_secret_rejects_everything(): void
    {
        $bare = Account::factory()->create();
        Http::fake();

        $this->notify("/api/webhooks/mercadopago/{$bare->id}", 'qualquer')->assertStatus(401);
    }

    public function test_legacy_url_without_account_uses_the_env_account_secret(): void
    {
        config(['gateways.env_credentials_account' => $this->a->id, 'gateways.mercadopago.enabled' => true,
            'gateways.mercadopago.access_token' => 'TEST-env', 'gateways.mercadopago.webhook_secret' => null]);
        // a conta A tem credencial no banco: o segredo vem dela
        $payment = $this->pending($this->a);
        Http::fake(['*mercadopago.test/v1/orders/*' => Http::response($this->mpPix(['status' => 'processed', 'status_detail' => 'accredited']), 200)]);

        $this->notify('/api/webhooks/mercadopago', 'segredo-A')->assertOk();

        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }

    public function test_legacy_url_is_rejected_when_no_account_uses_the_env(): void
    {
        $this->pending($this->a);

        $this->notify('/api/webhooks/mercadopago', 'segredo-A')->assertStatus(401);
    }
}
