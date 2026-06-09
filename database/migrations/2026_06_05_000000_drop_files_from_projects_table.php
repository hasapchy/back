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
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('files');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('files')->nullable();
        });
    }
};
