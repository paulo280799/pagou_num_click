<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('card_order_id')->nullable()->after('ide');
            $table->uuid('card_attempt_key')->nullable();
            $table->timestamp('card_attempt_started_at')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedTinyInteger('installments')->nullable();
            $table->string('acquirer_tid')->nullable();
            $table->string('acquirer_nsu')->nullable();
            $table->string('acquirer_auth_code')->nullable();
            $table->text('customer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'card_order_id',
                'card_attempt_key',
                'card_attempt_started_at',
                'card_brand',
                'card_last_four',
                'installments',
                'acquirer_tid',
                'acquirer_nsu',
                'acquirer_auth_code',
                'customer',
            ]);
        });
    }
};
