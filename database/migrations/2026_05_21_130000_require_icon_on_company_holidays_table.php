<?php

use App\Models\CompanyHoliday;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasColumn('company_holidays', 'icon')) {
            return;
        }

        $default = CompanyHoliday::DEFAULT_ICON;

        DB::table('company_holidays')
            ->whereNull('icon')
            ->orWhere('icon', '')
            ->update(['icon' => $default]);

        DB::statement(
            "ALTER TABLE company_holidays MODIFY icon VARCHAR(100) NOT NULL DEFAULT '".$default."'"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('company_holidays', 'icon')) {
            return;
        }

        DB::statement('ALTER TABLE company_holidays MODIFY icon VARCHAR(100) NULL DEFAULT NULL');
    }
};
