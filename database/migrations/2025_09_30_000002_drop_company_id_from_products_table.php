<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'company_id')) {
                // Сначала удаляем внешний ключ, если он существует
                try {
                    DB::statement('ALTER TABLE `products` DROP FOREIGN KEY `products_company_id_foreign`');
                } catch (\Throwable $e) {
                    // FK мог отсутствовать — игнорируем
                }
                // Затем удаляем колонку
                $table->dropColumn('company_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('date');
                // Индекс добавлять не обязательно; внешний ключ не восстанавливаем
            }
        });
    }
};


