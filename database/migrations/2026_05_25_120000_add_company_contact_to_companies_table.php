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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('name');
            $table->string('phone', 64)->nullable()->after('address');
            $table->string('registration_number', 128)->nullable()->after('phone');
            $table->string('email', 255)->nullable()->after('registration_number');
            $table->string('warehouse_number', 128)->nullable()->after('email');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'phone',
                'registration_number',
                'email',
                'warehouse_number',
            ]);
        });
    }
};
