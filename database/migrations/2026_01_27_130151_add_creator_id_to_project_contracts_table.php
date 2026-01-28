<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->foreignId('creator_id')->nullable()->after('project_id')->constrained('users')->onDelete('set null');
            $table->index(['creator_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
            $table->dropIndex(['creator_id']);
            $table->dropColumn('creator_id');
        });
    }
};
