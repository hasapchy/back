<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('rounding_decimals')->default(2)->after('show_deleted_transactions');
            $table->boolean('rounding_enabled')->default(true)->after('rounding_decimals');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['rounding_decimals', 'rounding_enabled']);
        });
    }
};

