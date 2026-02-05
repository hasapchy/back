<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE cash_registers DROP COLUMN is_rounding");
        } catch (\Throwable $e) {
            // колонка могла быть уже удалена — игнорируем
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE cash_registers ADD COLUMN is_rounding TINYINT(1) NOT NULL DEFAULT 0 AFTER balance");
        } catch (\Throwable $e) {
            // noop
        }
    }
};


