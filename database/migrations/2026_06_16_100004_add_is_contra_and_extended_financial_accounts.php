<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_accounts') && ! Schema::hasColumn('financial_accounts', 'is_contra')) {
            Schema::table('financial_accounts', function (Blueprint $table): void {
                $table->boolean('is_contra')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financial_accounts') && Schema::hasColumn('financial_accounts', 'is_contra')) {
            Schema::table('financial_accounts', function (Blueprint $table): void {
                $table->dropColumn('is_contra');
            });
        }
    }
};
