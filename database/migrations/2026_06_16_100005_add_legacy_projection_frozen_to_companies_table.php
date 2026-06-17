<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        if (Schema::hasColumn('companies', 'legacy_financial_projection_frozen')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('legacy_financial_projection_frozen')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'legacy_financial_projection_frozen')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('legacy_financial_projection_frozen');
            });
        }
    }
};
