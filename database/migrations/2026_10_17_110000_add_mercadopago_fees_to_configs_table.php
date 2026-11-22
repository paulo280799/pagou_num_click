<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            // tarifas do Mercado Pago (gateway de redundância); null = ainda não configurada (spike S12)
            $table->decimal('mp_pix_fee_percent', 5, 2)->nullable();
            $table->decimal('mp_card_fee_percent_1x', 5, 2)->nullable();
            $table->decimal('mp_card_fee_percent_2_6x', 5, 2)->nullable();
            $table->decimal('mp_card_fee_percent_7_12x', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn(['mp_pix_fee_percent', 'mp_card_fee_percent_1x', 'mp_card_fee_percent_2_6x', 'mp_card_fee_percent_7_12x']);
        });
    }
};
