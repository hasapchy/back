<?php

use Database\Seeders\FinancialAccountRuleSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new FinancialAccountRuleSeeder)->run();
    }

    public function down(): void
    {
    }
};
