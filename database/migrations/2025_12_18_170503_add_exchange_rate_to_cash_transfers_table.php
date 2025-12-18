<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_transfers', 'exchange_rate')) {
                $table->decimal('exchange_rate', 15, 6)->nullable()->after('amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('cash_transfers', 'exchange_rate')) {
                $table->dropColumn('exchange_rate');
            }
        });
    }
};


