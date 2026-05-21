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
        Schema::table('company_holidays', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('color');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('company_holidays', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
