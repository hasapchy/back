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
            $table->boolean('is_legacy')->default(false);
            $table->boolean('is_simple')->default(false);
            $table->string('status', 32)->default('purchasing');
        });

        DB::table('wh_receipts')->update([
            'is_legacy' => true,
            'is_simple' => false,
            'status' => 'purchasing',
        ]);
    }

    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'is_legacy',
                'is_simple',
                'status',
            ]);
        });
    }
};
