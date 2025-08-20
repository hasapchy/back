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
        Schema::create('order_af_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_af_id')->constrained('order_af')->onDelete('cascade');
            $table->foreignId('order_category_id')->constrained('order_categories')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['order_af_id', 'order_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_af_categories');
    }
};
