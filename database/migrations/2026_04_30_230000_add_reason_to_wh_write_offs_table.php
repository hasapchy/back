<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->string('reason', 32)->default('other')->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
