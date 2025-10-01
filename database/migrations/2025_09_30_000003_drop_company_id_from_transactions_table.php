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
        if (!Schema::hasTable('transactions')) {
            return;
        }

        if (Schema::hasColumn('transactions', 'company_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Пытаемся корректно удалить внешний ключ, если он существует
                try {
                    $table->dropForeign(['company_id']);
                } catch (\Throwable $e) {
                    // Игнорируем, если внешнего ключа нет или имя не совпало
                }

                // На некоторых БД остаётся индекс с именем внешнего ключа
                try {
                    $table->dropIndex('transactions_company_id_foreign');
                } catch (\Throwable $e) {
                    // Индекса может не быть — игнорируем
                }

                $table->dropColumn('company_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        if (!Schema::hasColumn('transactions', 'company_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->constrained()->onDelete('cascade');
            });
        }
    }
};


