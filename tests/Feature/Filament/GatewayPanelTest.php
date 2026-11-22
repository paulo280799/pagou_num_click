<?php

namespace Tests\Feature\Filament;

use App\Enums\GatewayEnum;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ConfigResource\Pages\EditConfig;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GatewayPanelTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->actingAs(User::factory()->create(['account_id' => $this->account->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function make(array $attrs = []): Payment
    {
        return Payment::factory()->create(['account_id' => $this->account->id] + $attrs);
    }

    public function test_list_shows_the_gateway_column(): void
    {
        $this->make(['pix_gateway' => 'PAGARME']);
        $this->make(['pix_gateway' => 'MERCADOPAGO']);

        $this->get('/admin/payments')->assertOk()->assertSee('Gateway')->assertSee('Pagar.me')->assertSee('Mercado Pago');
    }

    public function test_gateway_filter_uses_the_gateway_that_settled_the_sale(): void
    {
        $pagarme = $this->make(['pix_gateway' => 'PAGARME']);
        $mp = $this->make(['pix_gateway' => 'MERCADOPAGO']);
        $cardMp = $this->make(['pix_gateway' => 'PAGARME', 'card_gateway' => 'MERCADOPAGO', 'status' => 'PAID', 'payment_method' => 'CREDIT_CARD']);

        Livewire::test(ListPayments::class)
            ->filterTable('gateway', GatewayEnum::MERCADOPAGO->value)
            ->assertCanSeeTableRecords([$mp, $cardMp])
            ->assertCanNotSeeTableRecords([$pagarme])
            ->filterTable('gateway', GatewayEnum::PAGARME->value)
            ->assertCanSeeTableRecords([$pagarme])
            ->assertCanNotSeeTableRecords([$mp, $cardMp]);
    }

    public function test_view_modal_shows_gateway_and_raw_provider_status_in_technical_data(): void
    {
        $payment = $this->make([
            'pix_gateway' => 'MERCADOPAGO', 'card_gateway' => 'PAGARME',
            'provider_status' => 'in_process', 'provider_status_detail' => 'pending_contingency',
        ]);

        Livewire::test(ListPayments::class)
            ->mountTableAction('view', $payment)
            ->assertSee('Dados técnicos')
            ->assertSee('Gateway do Pix')
            ->assertSee('Gateway do cartão')
            ->assertSee('Status no provedor')
            ->assertSee('in_process')
            ->assertSee('pending_contingency');
    }

    public function test_view_modal_hides_gateway_rows_for_legacy_payments(): void
    {
        $payment = $this->make(['pix_gateway' => null, 'card_gateway' => null]);

        Livewire::test(ListPayments::class)
            ->mountTableAction('view', $payment)
            ->assertDontSee('Gateway do Pix')
            ->assertDontSee('Status no provedor');
    }

    public function test_report_csv_has_the_gateway_as_last_column(): void
    {
        $this->make(['pix_gateway' => 'MERCADOPAGO', 'refExternal' => 'pedido-mp']);
        $this->make(['pix_gateway' => 'PAGARME', 'refExternal' => 'pedido-pg']);
        $this->make(['pix_gateway' => null, 'refExternal' => 'pedido-legado']);

        $component = Livewire::test(Dashboard::class)->call('downloadReport');
        $csv = base64_decode($component->effects['download']['content']);

        $this->assertStringContainsString('"Pago em";Gateway', $csv);
        $this->assertMatchesRegularExpression('/pedido-mp;.*;"Mercado Pago"\r?\n/', $csv);
        $this->assertMatchesRegularExpression('/pedido-pg;.*;Pagar\.me\r?\n/', $csv);
        $this->assertMatchesRegularExpression('/pedido-legado;.*;\r?\n/', $csv);
    }

    public function test_mercado_pago_fee_section_only_appears_with_credentials_and_saves(): void
    {
        $config = Config::factory()->create(['account_id' => $this->account->id]);

        Livewire::test(EditConfig::class, ['record' => $config->id])->assertDontSee('Tarifas — Mercado Pago');

        config(['gateways.mercadopago.access_token' => 'TEST-mp-token']);

        Livewire::test(EditConfig::class, ['record' => $config->id])
            ->assertSee('Tarifas — Mercado Pago')
            ->fillForm(['mp_pix_fee_percent' => 1.2, 'mp_card_fee_percent_1x' => 4.9])
            ->call('save')
            ->assertHasNoFormErrors();

        $config->refresh();
        $this->assertSame(1.2, $config->mp_pix_fee_percent);
        $this->assertSame(4.9, $config->mp_card_fee_percent_1x);
        $this->assertNull($config->mp_card_fee_percent_2_6x);
    }
}
