<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // meios habilitados no checkout; null = legado (PIX + cartão)
            $table->json('payment_methods')->nullable();

            // checkout só-cartão não cria pedido PIX no provedor
            $table->string('ide')->nullable()->change();
            $table->string('qrCode')->nullable()->change();
            $table->string('copyPaste')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_methods');
            $table->string('ide')->nullable(false)->change();
            $table->string('qrCode')->nullable(false)->change();
            $table->string('copyPaste')->nullable(false)->change();
        });
    }
};
