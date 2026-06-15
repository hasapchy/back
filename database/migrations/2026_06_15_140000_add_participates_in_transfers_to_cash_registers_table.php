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
        Schema::table('cash_registers', function (Blueprint $table) {
            $table
                ->boolean('participates_in_transfers')
                ->default(true)
                ->after('is_working_minus')
                ->comment('1 - касса доступна в трансферах');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropColumn('participates_in_transfers');
        });
    }
};
