<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('currency_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('currency_histories', 'company_id')) {
                $table->foreignId('company_id')->nullable()->constrained()->onDelete('cascade')->after('currency_id');
            }
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('currency_histories', function (Blueprint $table) {
            if (Schema::hasColumn('currency_histories', 'company_id')) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            }
        });
    }
};
