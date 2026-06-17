<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `project_contracts` MODIFY `date` DATETIME NOT NULL');
        DB::statement('ALTER TABLE `wh_write_offs` MODIFY `date` DATETIME NULL');
        DB::statement('ALTER TABLE `salary_monthly_reports` MODIFY `date` DATETIME NOT NULL');
    }

    /**
     * @return void
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE `project_contracts` MODIFY `date` DATE NOT NULL');
        DB::statement('ALTER TABLE `wh_write_offs` MODIFY `date` DATE NULL');
        DB::statement('ALTER TABLE `salary_monthly_reports` MODIFY `date` DATE NOT NULL');
    }
};

