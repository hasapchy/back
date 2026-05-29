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
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN amount DECIMAL(20,5) NOT NULL COMMENT 'Сумма контракта'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE project_contracts MODIFY COLUMN amount DECIMAL(15,2) NOT NULL COMMENT 'Сумма контракта'");
    }
};
