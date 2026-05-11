<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('stock_alert_notify')->default(false)->after('type');
            $table->decimal('stock_min_quantity', 12, 5)->nullable()->after('stock_alert_notify');
            $table->boolean('low_stock_notification_armed')->default(false)->after('stock_min_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'stock_alert_notify',
                'stock_min_quantity',
                'low_stock_notification_armed',
            ]);
        });
    }
};
