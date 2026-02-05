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
        Schema::create('wh_write_offs', function (Blueprint $table) {
            $table->id(); 
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade'); 
            $table->text('note');
            $table->timestamps();
        });

    }

  
    public function down(): void
    {
        Schema::dropIfExists('wh_write_offs');
    }
};
