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
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['client_id']);
            $table->dropForeign(['creator_id']);
            $table->dropForeign(['currency_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('cash_id')->references('id')->on('cash_registers')->restrictOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['client_id']);
            $table->dropForeign(['creator_id']);
            $table->dropForeign(['currency_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('set null');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('cascade');
        });
    }
};
