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
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
        });

        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
        });

        Schema::table('wh_movements', function (Blueprint $table) {
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
            $table->dropColumn('creator_id');
        });
        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
            $table->dropColumn('creator_id');
        });
        Schema::table('wh_movements', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
            $table->dropColumn('creator_id');
        });
    }
};
