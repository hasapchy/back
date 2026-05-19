<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('rounding_orders_enabled')->default(true)->after('rounding_custom_threshold');
            $table->boolean('rounding_contracts_enabled')->default(false)->after('rounding_orders_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['rounding_orders_enabled', 'rounding_contracts_enabled']);
        });
    }
};
