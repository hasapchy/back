<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade');
            $table->enum('type', ['product', 'service', 'both'])->default('product');
            $table->timestamps();
        });
        
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
