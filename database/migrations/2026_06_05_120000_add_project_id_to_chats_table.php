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
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('company_id')
                ->constrained('projects')
                ->restrictOnDelete();

            $table->unique('project_id');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropUnique(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
