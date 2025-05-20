<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserTableSettingsTable extends Migration
{
    public function up()
    {
        Schema::create('user_table_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('table_name');
            $table->json('order');      // JSON для хранения порядка колонок
            $table->json('visibility'); // JSON для хранения видимости
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'table_name']); // Уникальность для пары user_id и table_name
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_table_settings');
    }
}