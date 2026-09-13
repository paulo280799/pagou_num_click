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
        Schema::create('configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('duration');
            $table->string('notification_url');
            $table->string('redirect_url');
            $table->string('api_token');
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
        Schema::dropIfExists('configs');
    }
};
