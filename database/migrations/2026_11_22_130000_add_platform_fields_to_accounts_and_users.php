<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // preenchido quando o usuário define a própria senha; null = convite pendente
            $table->timestamp('password_set_at')->nullable();
        });

        // usuários que já existem já têm senha
        DB::table('users')->update(['password_set_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'password_set_at']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
