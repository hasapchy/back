<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'total_price')) {
                $table->decimal('total_price', 20, 5)->default(0)->after('discount');
            }
        });

        DB::statement('UPDATE orders SET total_price = GREATEST(0, price - discount)');
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'total_price')) {
                $table->dropColumn('total_price');
            }
        });
    }
};
