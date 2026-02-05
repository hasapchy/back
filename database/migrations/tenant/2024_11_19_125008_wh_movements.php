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
        Schema::create('wh_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wh_from')->constrained('warehouses')->onDelete('cascade');
            $table->foreignId('wh_to')->constrained('warehouses')->onDelete('cascade');
            $table->text('note')->nullable();
            $table->timestamp('date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wh_movements');
    }
};
