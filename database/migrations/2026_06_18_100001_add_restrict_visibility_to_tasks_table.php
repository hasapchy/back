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
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('restrict_visibility')->default(true)->after('project_id');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('restrict_visibility');
        });
    }
};
