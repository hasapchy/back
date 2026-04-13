<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->renameColumn('is_tr_visible', 'is_visible');
        });
    }

    public function down(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->renameColumn('is_visible', 'is_tr_visible');
        });
    }
};
