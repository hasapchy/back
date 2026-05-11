<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('wh_waybill_products');
        Schema::dropIfExists('wh_waybills');

        DB::table('wh_receipts')
            ->whereNotIn('status', ['draft', 'completed'])
            ->update(['status' => 'draft']);

        Schema::table('wh_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('wh_receipts', 'is_legacy')) {
                $table->dropColumn('is_legacy');
            }
            if (Schema::hasColumn('wh_receipts', 'is_simple')) {
                $table->dropColumn('is_simple');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->boolean('is_legacy')->default(false);
            $table->boolean('is_simple')->default(false);
        });
    }
};
