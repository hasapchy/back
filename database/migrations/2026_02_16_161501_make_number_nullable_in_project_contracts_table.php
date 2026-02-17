<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE project_contracts MODIFY number VARCHAR(255) NULL COMMENT \'Номер контракта\'');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE project_contracts MODIFY number VARCHAR(255) NOT NULL COMMENT \'Номер контракта\'');
    }
};
