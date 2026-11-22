<?php

namespace Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Roda a migration contra linhas "antigas" (sem as colunas novas) para provar o backfill.
 * DDL no MySQL faz commit implícito: o teste limpa o que gravou em vez de contar com o rollback.
 */
class GatewayFieldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marks_existing_pagarme_rows_and_widens_qr_columns(): void
    {
        $migration = require database_path('migrations/2026_10_17_100000_add_gateway_fields_to_payments_table.php');
        $accountId = (string) Str::uuid();
        $ids = ['pix' => (string) Str::uuid(), 'cardOrder' => (string) Str::uuid(), 'cardAttempt' => (string) Str::uuid(), 'cardOnly' => (string) Str::uuid()];

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('payments', 'pix_gateway'));

            DB::table('accounts')->insert(['id' => $accountId, 'name' => 'Conta', 'created_at' => now(), 'updated_at' => now()]);
            $base = ['refExternal' => 'r', 'amount' => 10, 'status' => 'PENDING', 'payment_method' => 'PIX', 'duration' => 900, 'account_id' => $accountId, 'created_at' => now(), 'updated_at' => now()];
            // uma linha por insert: colunas diferentes entre elas
            foreach ([
                ['id' => $ids['pix'], 'ide' => 'order_1', 'qrCode' => 'u', 'copyPaste' => 'c'] + $base,
                ['id' => $ids['cardOrder'], 'ide' => 'order_2', 'card_order_id' => 'or_1', 'qrCode' => 'u', 'copyPaste' => 'c'] + $base,
                ['id' => $ids['cardAttempt'], 'ide' => 'order_3', 'card_attempt_key' => 'k', 'qrCode' => 'u', 'copyPaste' => 'c'] + $base,
                ['id' => $ids['cardOnly'], 'ide' => null, 'card_attempt_key' => 'k2', 'qrCode' => null, 'copyPaste' => null] + $base,
            ] as $payment) {
                DB::table('payments')->insert($payment);
            }

            $migration->up();

            $row = fn (string $key) => DB::table('payments')->where('id', $ids[$key])->first();

            $this->assertSame('PAGARME', $row('pix')->pix_gateway);
            $this->assertNull($row('pix')->card_gateway);
            $this->assertSame('PAGARME', $row('cardOrder')->card_gateway);
            $this->assertSame('PAGARME', $row('cardAttempt')->card_gateway);
            $this->assertNull($row('cardOnly')->pix_gateway); // sem PIX no provedor
            $this->assertSame('PAGARME', $row('cardOnly')->card_gateway);
            $this->assertNull($row('pix')->provider_status);

            // colunas largas o bastante para o QR base64 do Mercado Pago
            DB::table('payments')->where('id', $ids['pix'])->update(['qrCode' => str_repeat('Q', 60000), 'copyPaste' => str_repeat('C', 3000)]);
            $this->assertSame(60000, strlen($row('pix')->qrCode));
            $this->assertSame(3000, strlen($row('pix')->copyPaste));
        } finally {
            if (! Schema::hasColumn('payments', 'pix_gateway')) {
                $migration->up();
            }
            DB::table('payments')->whereIn('id', array_values($ids))->delete();
            DB::table('accounts')->where('id', $accountId)->delete();
        }
    }
}
