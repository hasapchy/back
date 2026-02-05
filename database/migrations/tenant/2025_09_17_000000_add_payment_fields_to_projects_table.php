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
        Schema::table('projects', function (Blueprint $table) {
            // Тип оплаты: 0 - безналичный, 1 - наличный
            $table->boolean('payment_type')->default(0)->comment('0 - безналичный, 1 - наличный');

            // Номер контракта (только для безналичного типа)
            $table->string('contract_number')->nullable()->comment('Номер контракта');

            // Возврат контракта (по умолчанию 0 - не вернули)
            $table->boolean('contract_returned')->default(0)->comment('0 - не вернули, 1 - вернули');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'contract_number', 'contract_returned']);
        });
    }
};
