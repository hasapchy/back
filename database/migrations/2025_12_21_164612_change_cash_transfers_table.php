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
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->renameColumn('from_cash_register_id', 'cash_id_from');
            $table->renameColumn('to_cash_register_id', 'cash_id_to');
            $table->renameColumn('from_transaction_id', 'tr_id_from');
            $table->renameColumn('to_transaction_id', 'tr_id_to');
            $table->timestamp('date')->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->dropColumn('date');
            $table->renameColumn('cash_id_from', 'from_cash_register_id');
            $table->renameColumn('cash_id_to', 'to_cash_register_id');
            $table->renameColumn('tr_id_from', 'from_transaction_id');
            $table->renameColumn('tr_id_to', 'to_transaction_id');
        });
    }
};
