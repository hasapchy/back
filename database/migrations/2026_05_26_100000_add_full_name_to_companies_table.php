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
        if (Schema::hasColumn('companies', 'full_name')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->string('full_name', 500)->nullable()->after('name');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'full_name')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('full_name');
        });
    }
};
