<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedDecimal('budget', 15, 2);
            // user_id — ссылка на central users без FK (таблица users только в central)
            $table->unsignedBigInteger('user_id');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->json('users')->nullable();
            $table->timestamp('date');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('projects');
    }
};
