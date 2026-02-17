<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_af', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['int', 'string', 'date', 'boolean', 'select', 'datetime']);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->string('default')->nullable();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_af');
    }
};
