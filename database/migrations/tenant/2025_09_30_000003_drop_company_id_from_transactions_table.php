<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
                // Удаляем FK только если он есть (в tenant-БД его могли не создавать)
                $fkName = $this->getForeignKeyName('transactions', 'company_id');
                if ($fkName !== null) {
                    $table->dropForeign($fkName);
                }

                $table->dropColumn('company_id');
            });
        }
    }

    /** Имя FK по таблице и колонке (MySQL). */
    private function getForeignKeyName(string $table, string $column): ?string
    {
        $db = DB::getDatabaseName();
        $row = DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1",
            [$db, $table, $column]
        );
        return $row ? ($row->CONSTRAINT_NAME ?? ($row->constraint_name ?? null)) : null;
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
                $table->unsignedBigInteger('company_id')->nullable();
            });
        }
    }
};


