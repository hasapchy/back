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
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_supplier')->default(false)->change();
            $table->boolean('is_conflict')->default(false)->change();
            $table->boolean('status')->default(true)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->tinyInteger('is_supplier')->default(0)->change();
            $table->tinyInteger('is_conflict')->default(0)->change();
            $table->tinyInteger('status')->default(1)->change();
        });
    }
};
