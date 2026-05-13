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
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $firstClientId = DB::table('clients')->orderBy('id')->value('id');
        if ($firstClientId !== null) {
            DB::table('wh_receipts')->whereNull('supplier_id')->update(['supplier_id' => $firstClientId]);
        }
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
        });
    }
};
