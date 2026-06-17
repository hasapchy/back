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
        if (Schema::hasColumn('comments', 'parent_id')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('creator_id')
                ->constrained('comments')
                ->cascadeOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('comments', 'parent_id')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
