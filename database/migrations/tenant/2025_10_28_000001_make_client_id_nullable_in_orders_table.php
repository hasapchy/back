<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Возвращаем обязательность client_id - клиент обязателен!
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Делаем client_id необязательным
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });
    }
};


