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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code')->unique();
            $table->string('currency_name');
            $table->string('symbol')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_currency_display')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
