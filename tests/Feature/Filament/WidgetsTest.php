<?php

namespace Tests\Feature\Filament;

use App\Enums\StatusPaymentEnum;
use App\Filament\Resources\WidgetsResource\Widgets\PagamentosResumoStats;
use App\Filament\Resources\WidgetsResource\Widgets\PagamentosStats;
use App\Models\Account;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagamentos_stats_counts_by_status(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->create(['account_id' => $account->id]));
        Payment::factory()->create(['account_id' => $account->id]);
        Payment::factory()->paid()->create(['account_id' => $account->id]);
        Payment::factory()->create(['account_id' => $account->id, 'status' => StatusPaymentEnum::FAILED]);
        Payment::factory()->create(['account_id' => $account->id, 'status' => StatusPaymentEnum::CANCELED]);

        $widget = new PagamentosStats();
        $stats = $this->callProtected($widget, 'getStats');

        $this->assertCount(4, $stats);
        $this->assertSame(4, $stats[0]->getValue());
        $this->assertSame(1, $stats[1]->getValue());
        $this->assertSame(1, $stats[2]->getValue());
        $this->assertSame(2, $stats[3]->getValue());
    }

    public function test_pagamentos_resumo_stats_calcula_valor_liquido_do_dia(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->create(['account_id' => $account->id]));
        Payment::factory()->paid()->create(['account_id' => $account->id, 'amount' => 100]);

        $widget = new PagamentosResumoStats();
        $liquido = $this->callProtected($widget, 'calcularValorLiquidoPorPeriodo', ['dia']);

        $this->assertSame('R$ 99,25', $liquido);
    }

    private function callProtected(object $object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
