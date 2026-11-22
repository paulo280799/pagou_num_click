<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
            // true só para o responsável convidado que ainda não definiu a senha (nenhum outro usuário fica "pendente")
            $table->boolean('invitation_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'invitation_pending']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
