<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('supplier_id');
        });

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $firstClientId = DB::table('clients')->orderBy('id')->value('id');
        if ($firstClientId !== null) {
            DB::table('wh_receipts')->whereNull('supplier_id')->update(['supplier_id' => $firstClientId]);
        }

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('supplier_id');
        });

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained('clients')->onDelete('cascade');
        });
    }
};
