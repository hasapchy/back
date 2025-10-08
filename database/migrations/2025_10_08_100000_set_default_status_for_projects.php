<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Устанавливаем status_id = 1 для всех существующих проектов, у которых он NULL
        DB::table('projects')
            ->whereNull('status_id')
            ->update(['status_id' => 1]);

        // Изменяем колонку, чтобы она не была nullable и имела значение по умолчанию
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('status_id')->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->default(null)->change();
        });
    }
};
