<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ide');
            $table->string('refExternal');
            $table->string('qrCode');
            $table->string('copyPaste');
            $table->double('amount');
            $table->string('status');
            $table->string('payment_method');
            $table->integer('duration');
            $table->timestamp('expirationDate')->nullable();
            $table->timestamp('paymentDate')->nullable();
            $table->text('redirect_url')->nullable();
            $table->text('notification_url')->nullable();
            $table->integer('notification_attempts')->default(0);
            $table->timestamp('last_notification_attempt')->nullable();
            $table->boolean('is_notified')->default(false);
            $table->uuid('account_id');
            $table->timestamps();

            $table->foreign('account_id')
            ->references('id')->on('accounts')
            ->onDelete('cascade');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
