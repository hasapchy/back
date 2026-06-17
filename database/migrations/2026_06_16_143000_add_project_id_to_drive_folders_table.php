<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('creator_id')
                ->constrained('projects')
                ->nullOnDelete();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->dropUnique(['project_id']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
