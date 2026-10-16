<?php

namespace Tests\Feature\Filament;

use App\Enums\DashboardPeriodEnum;
use App\Enums\StatusPaymentEnum;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DashboardChart;
use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\DashboardStatusStats;
use App\Models\Account;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stats(string $widget, ?string $period = null): array
    {
        $component = Livewire::test($widget, ['filters' => $period ? ['period' => $period] : null]);
        $method = new \ReflectionMethod($component->instance(), 'getStats');

        return collect($method->invoke($component->instance()))
            ->mapWithKeys(fn ($stat) => [$stat->getLabel() => [$stat->getValue(), $stat->getDescription()]])
            ->all();
    }

    public function test_admin_home_is_the_dashboard(): void
    {
        $this->get('/admin')->assertOk()->assertSee('Dashboard');
        $this->assertSame(url('/admin'), Dashboard::getUrl());
    }

    public function test_dashboard_widgets_are_registered_as_livewire_components(): void
    {
        $names = [
            'app.filament.widgets.dashboard-stats' => DashboardStats::class,
            'app.filament.widgets.dashboard-chart' => DashboardChart::class,
            'app.filament.widgets.dashboard-status-stats' => DashboardStatusStats::class,
        ];

        foreach ($names as $name => $class) {
            $this->assertInstanceOf($class, Livewire::new($name));
        }
    }

    public function test_dashboard_is_first_navigation_item(): void
    {
        $labels = collect(Filament::getNavigation())->flatMap->getItems()->map->getLabel()->values();

        $this->assertSame('Dashboard', $labels->first());
        $this->assertContains('Pagamentos', $labels);
    }

    public function test_stats_sum_created_authorized_count_and_average_ticket(): void
    {
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 100]);
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 50]);
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 30]);
        Payment::factory()->paid()->create(['amount' => 999, 'account_id' => Account::factory()->create()->id]);

        $stats = $this->stats(DashboardStats::class);

        $this->assertSame('R$ 180,00', $stats['Cobranças criadas'][0]);
        $this->assertSame('R$ 150,00', $stats['Cobranças autorizadas'][0]);
        $this->assertSame('3', $stats['Número de cobranças'][0]);
        $this->assertSame('R$ 75,00', $stats['Ticket médio'][0]);
    }

    public function test_authorized_card_shows_net_revenue_using_stored_fee_and_legacy_fallback(): void
    {
        // Pix legado sem snapshot: 100 − 0,75
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 100]);
        // cartão com snapshot: 200 − 9
        Payment::factory()->cardPaid()->create(['account_id' => $this->account->id, 'amount' => 200, 'fee_percent' => 4.5, 'fee_amount' => 9]);
        // cartão sem tarifa configurada: 50 − 0
        Payment::factory()->cardPaid()->create(['account_id' => $this->account->id, 'amount' => 50]);
        // não pago e de outra conta não entram
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 999]);
        Payment::factory()->paid()->create(['amount' => 500, 'account_id' => Account::factory()->create()->id]);

        $this->assertSame('3 pagas · líquido R$ 340,25', $this->stats(DashboardStats::class)['Cobranças autorizadas'][1]);
    }

    public function test_ticket_is_zero_without_paid_payments(): void
    {
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 30]);

        $this->assertSame('R$ 0,00', $this->stats(DashboardStats::class)['Ticket médio'][0]);
    }

    public function test_status_cards_show_amount_count_and_percentage(): void
    {
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 100]);
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 20]);
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 30]);
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 10, 'status' => StatusPaymentEnum::CANCELED]);

        $stats = $this->stats(DashboardStatusStats::class);

        $this->assertSame(['R$ 120,00', '2 cobranças (50,00%)'], $stats['Pago']);
        $this->assertSame(['R$ 30,00', '1 cobrança (25,00%)'], $stats['Pendente']);
        $this->assertSame(['R$ 10,00', '1 cobrança (25,00%)'], $stats['Cancelado']);
        $this->assertSame(['R$ 0,00', '0 cobranças (0,00%)'], $stats['Falha']);
    }

    public function test_period_filter_excludes_older_payments(): void
    {
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 100]);
        Payment::factory()->paid()->create([
            'account_id' => $this->account->id,
            'amount' => 40,
            'created_at' => now()->subDays(3),
        ]);

        $this->assertSame('R$ 100,00', $this->stats(DashboardStats::class, 'today')['Cobranças criadas'][0]);
        $this->assertSame('R$ 140,00', $this->stats(DashboardStats::class, 'week')['Cobranças criadas'][0]);
    }

    public function test_unknown_period_falls_back_to_today(): void
    {
        $this->assertSame(DashboardPeriodEnum::TODAY, DashboardPeriodEnum::fromFilters(['period' => 'nope']));
        $this->assertSame(DashboardPeriodEnum::TODAY, DashboardPeriodEnum::fromFilters(null));
    }

    public function test_chart_groups_paid_volume_by_hour_today(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(10, 30));
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 40, 'created_at' => Carbon::today()->setTime(3, 15)]);
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 10, 'created_at' => Carbon::today()->setTime(3, 45)]);
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 99, 'created_at' => Carbon::today()->setTime(3, 50)]);

        $chart = Livewire::test(DashboardChart::class, ['filters' => ['period' => 'today']]);
        $data = (new \ReflectionMethod($chart->instance(), 'getData'))->invoke($chart->instance());

        $this->assertCount(11, $data['labels']);
        $this->assertSame('00:00', $data['labels'][0]);
        $this->assertSame('10:00', $data['labels'][10]);
        $this->assertSame(50.0, $data['datasets'][0]['data'][3]);
        $this->assertSame(0.0, $data['datasets'][0]['data'][4]);
    }

    public function test_chart_count_metric_counts_every_status_per_day(): void
    {
        Payment::factory()->paid()->create(['account_id' => $this->account->id]);
        Payment::factory()->create(['account_id' => $this->account->id]);

        $chart = Livewire::test(DashboardChart::class, ['filters' => ['period' => 'week']])->set('filter', 'count');
        $data = (new \ReflectionMethod($chart->instance(), 'getData'))->invoke($chart->instance());

        $this->assertCount(7, $data['labels']);
        $this->assertSame(2, end($data['datasets'][0]['data']));
        $this->assertSame(0, $data['datasets'][0]['data'][0]);
    }

    public function test_chart_options_are_serialized_with_a_real_tick_callback(): void
    {
        $chart = Livewire::test(DashboardChart::class, ['filters' => ['period' => 'year']]);
        $options = (new \ReflectionMethod($chart->instance(), 'getOptions'))->invoke($chart->instance());

        // RawJs aninhado em array vira `{}` e o Chart.js quebra; tem que ser o objeto inteiro
        $this->assertInstanceOf(\Filament\Support\RawJs::class, $options);
        $this->assertStringContainsString('callback: (value) =>', $options->toHtml());

        $count = $chart->set('filter', 'count');
        $this->assertIsArray((new \ReflectionMethod($count->instance(), 'getOptions'))->invoke($count->instance()));
    }

    public function test_download_report_streams_csv_for_selected_period_and_own_account_only(): void
    {
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 100, 'refExternal' => 'pedido-1']);
        Payment::factory()->create(['account_id' => $this->account->id, 'amount' => 30, 'refExternal' => '=HYPERLINK("x")']);
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'amount' => 40, 'refExternal' => 'velho', 'created_at' => now()->subDays(3)]);
        Payment::factory()->paid()->create(['amount' => 999, 'refExternal' => 'outra-conta', 'account_id' => Account::factory()->create()->id]);

        $response = Livewire::test(Dashboard::class)->call('downloadReport');
        $response->assertFileDownloaded('relatorio-pagamentos-today-'.now()->format('Ymd').'.csv');

        $csv = $this->downloadedContent(Livewire::test(Dashboard::class)->call('downloadReport'));

        $this->assertStringStartsWith("\xEF\xBB\xBFReferência;ID;Meio;Valor;Status;\"Tarifa (%)\";\"Tarifa (R$)\";Líquido;\"Criado em\";\"Pago em\"", $csv);
        $this->assertStringContainsString('pedido-1;', $csv);
        $this->assertStringContainsString(';100,00;Pago;0,75;0,75;99,25;', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK', $csv);
        $this->assertStringContainsString(';30,00;"Aguardando pagamento";;;;"', $csv);
        $this->assertStringNotContainsString('velho', $csv);
        $this->assertStringNotContainsString('outra-conta', $csv);

        $week = Livewire::test(Dashboard::class)->set('filters.period', 'week')->call('downloadReport');
        $this->assertStringContainsString('velho', $this->downloadedContent($week));
    }

    private function downloadedContent($component): string
    {
        $download = $component->effects['download'];

        return base64_decode($download['content']);
    }

    public function test_year_period_buckets_by_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 16, 12));

        $buckets = DashboardPeriodEnum::YEAR->buckets();

        $this->assertCount(10, $buckets);
        $this->assertSame('Jan', $buckets['2026-01']);
        $this->assertSame('Out', $buckets['2026-10']);
    }

    public function test_page_filter_form_changes_period(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSet('filters.period', 'today')
            ->assertFormSet(['period' => 'today'], 'filtersForm')
            ->set('filters.period', 'year')
            ->assertSet('filters.period', 'year');
    }
}
