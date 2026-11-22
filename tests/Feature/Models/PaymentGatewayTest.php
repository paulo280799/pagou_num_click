<?php

namespace Tests\Feature\Models;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $config = []): Account
    {
        $account = Account::factory()->create();
        Config::factory()->create(['account_id' => $account->id] + $config);

        return $account;
    }

    private array $fees = [
        'pix_fee_percent' => 0.75,
        'card_fee_percent_1x' => 3.5, 'card_fee_percent_2_6x' => 4.5, 'card_fee_percent_7_12x' => 5.5,
        'mp_pix_fee_percent' => 1.2,
        'mp_card_fee_percent_1x' => 4.9, 'mp_card_fee_percent_2_6x' => 5.9, 'mp_card_fee_percent_7_12x' => 6.9,
    ];

    // ---- accessor `gateway` (tarifa/painel; nunca roteamento) ------------------------------------

    public function test_gateway_accessor_follows_the_medium_that_settled_the_sale(): void
    {
        $account = Account::factory()->create();
        $make = fn (array $a) => Payment::factory()->create(['account_id' => $account->id] + $a);

        // PIX em aberto
        $this->assertSame(GatewayEnum::MERCADOPAGO, $make(['pix_gateway' => 'MERCADOPAGO'])->gateway);

        // pago no PIX
        $this->assertSame(GatewayEnum::PAGARME, $make(['pix_gateway' => 'PAGARME', 'card_gateway' => 'MERCADOPAGO', 'status' => 'PAID'])->gateway);

        // pago no cartão: vale o gateway do cartão, mesmo com o PIX em outro
        $cardPaid = $make(['pix_gateway' => 'PAGARME', 'card_gateway' => 'MERCADOPAGO', 'status' => 'PAID', 'payment_method' => 'CREDIT_CARD']);
        $this->assertSame(GatewayEnum::MERCADOPAGO, $cardPaid->gateway);

        // cartão em análise: payment_method ainda é PIX, então o accessor devolve o gateway do PIX (por isso não roteia)
        $inReview = $make(['pix_gateway' => 'PAGARME', 'card_gateway' => 'MERCADOPAGO', 'card_attempt_key' => 'k']);
        $this->assertSame(GatewayEnum::PAGARME, $inReview->gateway);
        $this->assertSame(GatewayEnum::MERCADOPAGO, $inReview->cardGatewayOwner());
        $this->assertSame(GatewayEnum::PAGARME, $inReview->pixGatewayOwner());

        // só-cartão pendente, sem PIX: cai no gateway do cartão
        $this->assertSame(GatewayEnum::MERCADOPAGO, $make(['pix_gateway' => null, 'card_gateway' => 'MERCADOPAGO', 'ide' => null])->gateway);

        // sem nenhum registro (linha antiga): sem gateway no painel, mas o dono do id é o Pagar.me
        $legacy = $make(['pix_gateway' => null, 'card_gateway' => null]);
        $this->assertNull($legacy->gateway);
        $this->assertSame(GatewayEnum::PAGARME, $legacy->pixGatewayOwner());
        $this->assertSame(GatewayEnum::PAGARME, $legacy->cardGatewayOwner());
    }

    public function test_settled_on_scope_matches_the_accessor(): void
    {
        $account = Account::factory()->create();
        $make = fn (array $a) => Payment::factory()->create(['account_id' => $account->id] + $a);

        $pixPagarme = $make(['pix_gateway' => 'PAGARME']);
        $pixMp = $make(['pix_gateway' => 'MERCADOPAGO']);
        $cardMp = $make(['pix_gateway' => 'PAGARME', 'card_gateway' => 'MERCADOPAGO', 'status' => 'PAID', 'payment_method' => 'CREDIT_CARD']);
        $cardOnlyMp = $make(['pix_gateway' => null, 'card_gateway' => 'MERCADOPAGO', 'ide' => null]);

        $mp = Payment::withoutGlobalScopes()->settledOn(GatewayEnum::MERCADOPAGO)->pluck('id')->all();
        $pg = Payment::withoutGlobalScopes()->settledOn(GatewayEnum::PAGARME)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$pixMp->id, $cardMp->id, $cardOnlyMp->id], $mp);
        $this->assertSame([$pixPagarme->id], $pg);
    }

    // ---- tarifas por gateway --------------------------------------------------------------------

    public function test_fee_without_gateway_argument_uses_the_pagarme_table(): void
    {
        $account = $this->account($this->fees);
        $payment = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100]);

        $this->assertSame(['fee_percent' => 0.75, 'fee_amount' => 0.75], $payment->feeAttributesFor(PaymentMethodEnum::PIX));
        $this->assertSame(['fee_percent' => 3.5, 'fee_amount' => 3.5], $payment->feeAttributesFor(PaymentMethodEnum::CREDIT_CARD, 1));
    }

    public function test_fee_follows_the_gateway_recorded_on_the_payment(): void
    {
        $account = $this->account($this->fees);
        $pix = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100, 'pix_gateway' => 'MERCADOPAGO']);
        $card = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100, 'pix_gateway' => 'PAGARME', 'card_gateway' => 'MERCADOPAGO']);

        $this->assertSame(['fee_percent' => 1.2, 'fee_amount' => 1.2], $pix->feeAttributesFor(PaymentMethodEnum::PIX));
        $this->assertSame(['fee_percent' => 4.9, 'fee_amount' => 4.9], $card->feeAttributesFor(PaymentMethodEnum::CREDIT_CARD, 1));
        $this->assertSame(['fee_percent' => 6.9, 'fee_amount' => 6.9], $card->feeAttributesFor(PaymentMethodEnum::CREDIT_CARD, 12));
        // o 3º argumento tem precedência
        $this->assertSame(['fee_percent' => 0.75, 'fee_amount' => 0.75], $pix->feeAttributesFor(PaymentMethodEnum::PIX, null, GatewayEnum::PAGARME));
    }

    public function test_mercado_pago_fee_is_null_until_configured(): void
    {
        $account = $this->account(['pix_fee_percent' => 0.75]);
        $payment = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100, 'pix_gateway' => 'MERCADOPAGO']);

        $this->assertSame(['fee_percent' => null, 'fee_amount' => null], $payment->feeAttributesFor(PaymentMethodEnum::PIX));
    }

    public function test_config_fee_percent_by_gateway_and_installment_tier(): void
    {
        $config = new Config($this->fees);

        $this->assertSame(0.75, $config->feePercentFor(PaymentMethodEnum::PIX));
        $this->assertSame(1.2, $config->feePercentFor(PaymentMethodEnum::PIX, null, GatewayEnum::MERCADOPAGO));
        $this->assertSame(4.9, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 1, GatewayEnum::MERCADOPAGO));
        $this->assertSame(5.9, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 4, GatewayEnum::MERCADOPAGO));
        $this->assertSame(6.9, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 9, GatewayEnum::MERCADOPAGO));
    }

    public function test_mark_card_paid_snapshots_the_card_gateway_fee(): void
    {
        $account = $this->account($this->fees);
        $payment = Payment::factory()->withCustomer()->create(['account_id' => $account->id, 'amount' => 200, 'card_gateway' => 'MERCADOPAGO', 'card_attempt_key' => 'k']);

        $payment->markCardPaid(new ChargeResult(ChargeOutcome::PAID, orderId: '55', installments: 1));

        $payment->refresh();
        $this->assertSame(4.9, $payment->fee_percent);
        $this->assertSame(9.8, $payment->fee_amount);
    }

    public function test_net_revenue_with_mixed_gateways_uses_each_snapshot(): void
    {
        $account = Account::factory()->create();
        Payment::factory()->paid()->create(['account_id' => $account->id, 'amount' => 100, 'fee_amount' => 0.75, 'fee_percent' => 0.75, 'pix_gateway' => 'PAGARME']);
        Payment::factory()->paid()->create(['account_id' => $account->id, 'amount' => 100, 'fee_amount' => 1.2, 'fee_percent' => 1.2, 'pix_gateway' => 'MERCADOPAGO']);
        Payment::factory()->paid()->create(['account_id' => $account->id, 'amount' => 100, 'fee_amount' => 4.9, 'fee_percent' => 4.9, 'payment_method' => 'CREDIT_CARD', 'card_gateway' => 'MERCADOPAGO']);

        $this->assertSame(293.15, round(Payment::netRevenue(Payment::withoutGlobalScopes()->where('account_id', $account->id)), 2));
    }

    // ---- status cru ----------------------------------------------------------------------------

    public function test_record_provider_status_reports_only_changes(): void
    {
        $payment = Payment::factory()->create(['account_id' => Account::factory()->create()->id]);

        $this->assertTrue($payment->recordProviderStatus('pending', 'pending_waiting_transfer'));
        $this->assertFalse($payment->recordProviderStatus('pending', 'pending_waiting_transfer'));
        $this->assertTrue($payment->recordProviderStatus('pending', 'pending_review_manual')); // só o detalhe mudou
        $this->assertFalse($payment->recordProviderStatus(null, null));

        $payment->refresh();
        $this->assertSame('pending', $payment->provider_status);
        $this->assertSame('pending_review_manual', $payment->provider_status_detail);
    }

    public function test_long_qr_code_and_copy_paste_persist_without_truncation(): void
    {
        $payment = Payment::factory()->create([
            'account_id' => Account::factory()->create()->id,
            'qrCode' => 'data:image/png;base64,'.str_repeat('Q', 40000),
            'copyPaste' => str_repeat('C', 2000),
        ]);

        $payment->refresh();
        $this->assertSame(40000 + 22, strlen($payment->qrCode));
        $this->assertSame(2000, strlen($payment->copyPaste));
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
    }
}
