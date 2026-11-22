<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * api_token deixa de ser guardado em texto puro: fica só o SHA-256, um prefixo para identificar o token no painel
 * e, durante a rotação, o hash anterior com prazo de validade. Os tokens em uso continuam valendo (backfill do hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->string('api_token_hash', 64)->nullable()->unique()->after('redirect_url');
            $table->string('api_token_prefix', 12)->nullable()->after('api_token_hash');
            $table->timestamp('api_token_last_used_at')->nullable()->after('api_token_prefix');
            $table->string('previous_api_token_hash', 64)->nullable()->index()->after('api_token_last_used_at');
            $table->timestamp('previous_api_token_expires_at')->nullable()->after('previous_api_token_hash');
        });

        DB::table('configs')->whereNotNull('api_token')->orderBy('id')->each(function ($row) {
            DB::table('configs')->where('id', $row->id)->update([
                'api_token_hash' => hash('sha256', $row->api_token),
                'api_token_prefix' => substr($row->api_token, 0, 8),
            ]);
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn('api_token');
        });
    }

    public function down(): void
    {
        // os tokens originais não são recuperáveis (só o hash foi guardado): as contas precisam regenerar o token
        Schema::table('configs', function (Blueprint $table) {
            $table->string('api_token')->nullable()->after('redirect_url');
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->dropUnique(['api_token_hash']);
            $table->dropIndex(['previous_api_token_hash']);
            $table->dropColumn(['api_token_hash', 'api_token_prefix', 'api_token_last_used_at', 'previous_api_token_hash', 'previous_api_token_expires_at']);
        });
    }
};
