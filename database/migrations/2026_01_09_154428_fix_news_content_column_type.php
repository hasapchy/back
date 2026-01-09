<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Изменяем тип колонки content на LONGTEXT для поддержки больших HTML контентов с изображениями
        DB::statement('ALTER TABLE `news` MODIFY COLUMN `content` LONGTEXT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Возвращаем обратно к TEXT
        DB::statement('ALTER TABLE `news` MODIFY COLUMN `content` TEXT');
    }
};
