<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // gateway que criou o PIX / que recebeu a tentativa de cartão; null = meio nunca usado (ex.: só-cartão sem PIX)
            $table->string('pix_gateway')->nullable();
            $table->string('card_gateway')->nullable();

            // status cru do provedor, para auditoria e para logar só quando muda
            $table->string('provider_status')->nullable();
            $table->string('provider_status_detail')->nullable();

            // o QR do Mercado Pago é base64 de vários KB e o copia-e-cola pode passar de 255
            $table->text('qrCode')->nullable()->change();
            $table->text('copyPaste')->nullable()->change();
        });

        // tudo o que existe hoje foi processado pelo Pagar.me
        DB::table('payments')->whereNotNull('ide')->update(['pix_gateway' => 'PAGARME']);
        DB::table('payments')
            ->where(fn ($q) => $q->whereNotNull('card_order_id')->orWhereNotNull('card_attempt_key'))
            ->update(['card_gateway' => 'PAGARME']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['pix_gateway', 'card_gateway', 'provider_status', 'provider_status_detail']);
        });

        // qrCode/copyPaste ficam em text: reduzir para 255 truncaria QR do Mercado Pago já gravado
    }
};
