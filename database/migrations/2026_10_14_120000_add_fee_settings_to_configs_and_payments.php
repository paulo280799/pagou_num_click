<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            // % de tarifa por meio; Pix preserva o 0,75% que era fixo no código. Cartão null = ainda não configurada.
            $table->decimal('pix_fee_percent', 5, 2)->default(0.75);
            $table->decimal('card_fee_percent_1x', 5, 2)->nullable();
            $table->decimal('card_fee_percent_2_6x', 5, 2)->nullable();
            $table->decimal('card_fee_percent_7_12x', 5, 2)->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            // snapshot da tarifa aplicada quando o pagamento foi confirmado; null = legado/sem tarifa configurada
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->decimal('fee_amount', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['fee_percent', 'fee_amount']);
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn(['pix_fee_percent', 'card_fee_percent_1x', 'card_fee_percent_2_6x', 'card_fee_percent_7_12x']);
        });
    }
};
