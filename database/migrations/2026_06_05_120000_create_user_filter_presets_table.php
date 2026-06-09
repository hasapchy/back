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
        Schema::create('user_filter_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('source', 32);
            $table->string('name', 255);
            $table->string('icon', 64);
            $table->string('color', 7);
            $table->json('filters');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['user_id', 'company_id', 'source', 'name']);
            $table->index(['user_id', 'company_id', 'source']);
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('user_filter_presets');
    }
};
