<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
        });

        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
        });

        Schema::table('wh_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
        Schema::table('wh_movements', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
