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
        Schema::table('wh_users', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['warehouse_id', 'user_id']);

            // Переименовываем колонку
            $table->renameColumn('user_id', 'creator_id');

            // Добавляем обратно foreign key и unique
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['warehouse_id', 'creator_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wh_users', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
        $table->dropUnique(['warehouse_id', 'creator_id']);

        $table->renameColumn('creator_id', 'user_id');

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->unique(['warehouse_id', 'user_id']);
        });
    }
};
