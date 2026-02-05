<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('direct_key')->nullable()->after('type');
            $table->unique(['company_id', 'direct_key'], 'chats_company_direct_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropUnique('chats_company_direct_key_unique');
            $table->dropColumn('direct_key');
        });
    }
};
