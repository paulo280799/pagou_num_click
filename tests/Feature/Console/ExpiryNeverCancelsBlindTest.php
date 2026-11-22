<?php

namespace Tests\Feature\Console;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\GatewayCredential;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

/** Um PIX vencido só é cancelado quando o provedor deixa claro que não foi pago: credencial recusada nunca cancela. */
class ExpiryNeverCancelsBlindTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private function expiredPix(): Payment
    {
        config(['gateways.env_credentials_account' => null]);
        $account = Account::factory()->create();
        GatewayCredential::withoutGlobalScopes()->create(['account_id' => $account->id, 'gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_ERRADA'], 'enabled' => true]);

        // vencido há muito mais que a janela de graça
        return Payment::factory()->pending()->create(['account_id' => $account->id, 'ide' => 'or_1', 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->subDays(2)]);
    }

    public function test_rejected_credential_never_cancels_even_after_the_grace_window(): void
    {
        $payment = $this->expiredPix();
        Http::fake(['*/v5/orders/*' => Http::response(['message' => 'Authorization has been denied'], 401)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_undecipherable_provider_answer_never_cancels(): void
    {
        $payment = $this->expiredPix();
        Http::fake(['*/v5/orders/*' => Http::response(['message' => 'Order not found'], 404)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_a_paid_pix_is_recognised_once_the_credential_is_fixed(): void
    {
        $payment = $this->expiredPix();
        Http::fake(['*/v5/orders/*' => Http::response(['id' => 'or_1', 'status' => 'paid', 'charges' => [['status' => 'paid', 'payment_method' => 'pix', 'last_transaction' => ['status' => 'paid']]]], 200)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }

    public function test_a_confirmed_unpaid_expired_pix_is_still_canceled(): void
    {
        $payment = $this->expiredPix();
        Http::fake(['*/v5/orders/*' => Http::response(['id' => 'or_1', 'status' => 'pending', 'charges' => [['status' => 'pending', 'last_transaction' => ['status' => 'waiting_payment']]]], 200)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::CANCELED, $payment->refresh()->status);
    }
}
