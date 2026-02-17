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
        $columns = [
            'number' => "VARCHAR(255) NULL",
            'amount' => "DECIMAL(15,2) NOT NULL",
            'date' => "DATE NOT NULL",
            'returned' => "TINYINT(1) NOT NULL DEFAULT 0",
            'files' => "JSON NULL",
            'note' => "TEXT NULL",
        ];
        foreach ($columns as $col => $def) {
            DB::statement("ALTER TABLE project_contracts MODIFY COLUMN {$col} {$def} COMMENT ''");
        }
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN type TINYINT NOT NULL DEFAULT 0 COMMENT '0 - безнал, 1 - нал'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN number VARCHAR(255) NULL COMMENT 'Номер контракта'");
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN amount DECIMAL(15,2) NOT NULL COMMENT 'Сумма контракта'");
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN date DATE NOT NULL COMMENT 'Дата контракта'");
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN returned TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Контракт возвращен'");
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN files JSON NULL COMMENT 'Файлы контракта'");
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN note TEXT NULL COMMENT 'Примечание к контракту'");
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN type TINYINT NOT NULL DEFAULT 0 COMMENT 'Тип контракта: 0 - безналичный, 1 - наличный'");
    }
};
