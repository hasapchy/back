<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cash_id')->nullable()->constrained('cash_registers')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {

            $table->dropConstrainedForeignId('cash_id');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
