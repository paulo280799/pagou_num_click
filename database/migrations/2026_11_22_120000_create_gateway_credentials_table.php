<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('gateway', 20);          // GatewayEnum: PAGARME | MERCADOPAGO
            $table->text('credentials');            // encrypted:array (APP_KEY); nunca volta inteiro para a tela
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_validation_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'gateway']);
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_credentials');
    }
};
