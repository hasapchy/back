<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_balances', function (Blueprint $table) {
            $table->tinyInteger('type')->default(1)->after('currency_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
