<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table
                ->boolean('is_cash')
                ->default(true)
                ->after('company_id')
                ->comment('1 - наличная касса, 0 - безналичная касса');

            $table
                ->string('icon', 100)
                ->nullable()
                ->after('is_cash')
                ->comment('CSS класс иконки (Font Awesome)');
        });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropColumn(['is_cash', 'icon']);
        });
    }
};

