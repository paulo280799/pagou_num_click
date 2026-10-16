<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            // padrão preserva o comportamento anterior (CARD_MAX_INSTALLMENTS=12)
            $table->boolean('card_installments_enabled')->default(true);
            $table->unsignedTinyInteger('card_max_installments')->default(12);
        });

        Schema::table('payments', function (Blueprint $table) {
            // snapshot da Config na criação; null = legado (usa o teto de 12)
            $table->unsignedTinyInteger('max_installments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('max_installments');
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn(['card_installments_enabled', 'card_max_installments']);
        });
    }
};
