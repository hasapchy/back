<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('orig_unit_price', 15, 5)->nullable()->after('price');
            $table->foreignId('orig_currency_id')->nullable()->after('orig_unit_price')->constrained('currencies')->nullOnDelete();
        });

        Schema::table('order_temp_products', function (Blueprint $table) {
            $table->decimal('orig_unit_price', 15, 5)->nullable()->after('price');
            $table->foreignId('orig_currency_id')->nullable()->after('orig_unit_price')->constrained('currencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropForeign(['orig_currency_id']);
            $table->dropColumn(['orig_unit_price', 'orig_currency_id']);
        });

        Schema::table('order_temp_products', function (Blueprint $table) {
            $table->dropForeign(['orig_currency_id']);
            $table->dropColumn(['orig_unit_price', 'orig_currency_id']);
        });
    }
};
