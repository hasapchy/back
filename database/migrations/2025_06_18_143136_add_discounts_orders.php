<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
             $table->decimal('price', 15, 2)
                  ->default(0)->after('note');
            $table->decimal('discount', 15, 2)->default(0)->after('price');
            $table->decimal('total_price', 15, 2)
                  ->default(0)->after('discount');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['price','discount', 'total_price']);
        });
    }
};
