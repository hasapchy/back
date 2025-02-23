<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clients_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade'); 
            $table->string('phone')->unique();
            $table->boolean('is_sms')->default(false); 
            $table->timestamps(); 
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients_phones');
    }
};

