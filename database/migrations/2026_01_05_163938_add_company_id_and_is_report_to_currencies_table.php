<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (!Schema::hasColumn('currencies', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            }

            if (!Schema::hasColumn('currencies', 'is_report')) {
                $table->boolean('is_report')->default(false)->after('is_default');
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (Schema::hasColumn('currencies', 'company_id')) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            }

            if (Schema::hasColumn('currencies', 'is_report')) {
                $table->dropColumn('is_report');
            }
        });
    }
};
