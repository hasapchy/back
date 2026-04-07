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
        if (Schema::hasTable('comments')) {
            return;
        }

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->morphs('commentable');
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
