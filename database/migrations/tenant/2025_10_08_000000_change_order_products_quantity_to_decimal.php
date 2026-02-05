<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('order_products', function (Blueprint $table) {
            // Изменяем тип поля quantity с integer на decimal(12, 4)
            // Это позволит хранить дробные значения количества с высокой точностью
            // Например: 2.5, 10.75, 7.9884
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_products', function (Blueprint $table) {
            // Возвращаем обратно тип integer
            $table->integer('quantity')->default(1)->change();
        });
    }
};
