<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clients_emails', function (Blueprint $table) {
            $table->id(); // Первичный ключ
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade'); // Внешний ключ на clients
            $table->string('email')->unique(); // Email клиента
            $table->timestamps(); // created_at и updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients_emails');
    }
};

