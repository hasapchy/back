<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clients_phones', function (Blueprint $table) {
            $table->id(); // Первичный ключ
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade'); // Внешний ключ на clients
            $table->string('phone')->unique();; // Номер телефона
            $table->boolean('is_sms')->default(false); // Флаг, указывающий, используется ли для SMS
            $table->timestamps(); // created_at и updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients_phones');
    }
};

