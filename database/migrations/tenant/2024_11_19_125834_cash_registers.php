<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id(); 
            $table->string('name'); 
            $table->decimal('balance', 15, 2)->default(0.00); 
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade'); 
            $table->json('users')->nullable();
            $table->timestamps(); 
        });
        
    }

    public function down(): void
    {
       
        Schema::dropIfExists('cash_registers');
    }
};
