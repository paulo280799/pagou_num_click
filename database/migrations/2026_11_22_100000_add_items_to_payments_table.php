<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Itens do pedido (centavos) para repassar ao gateway: o cartão nasce depois, no pay-card. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->json('items')->nullable()->after('customer');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('items');
        });
    }
};
