<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('rounding_quantity_decimals')->default(2)->after('rounding_custom_threshold');
            $table->boolean('rounding_quantity_enabled')->default(true)->after('rounding_quantity_decimals');
            $table->string('rounding_quantity_direction', 20)->nullable()->default('standard')->after('rounding_quantity_enabled');
            $table->decimal('rounding_quantity_custom_threshold', 10, 5)->nullable()->after('rounding_quantity_direction');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'rounding_quantity_decimals',
                'rounding_quantity_enabled',
                'rounding_quantity_direction',
                'rounding_quantity_custom_threshold'
            ]);
        });
    }
};

