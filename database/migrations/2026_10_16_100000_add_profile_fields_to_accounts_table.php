<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('document', 14)->nullable()->after('legal_name');
            $table->string('contact_email')->nullable()->after('document');
            $table->string('phone', 11)->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'document', 'contact_email', 'phone']);
        });
    }
};
