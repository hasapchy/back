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
        if (! Schema::hasColumn('users', 'creator_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('creator_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'creator_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['creator_id']);
                $table->dropColumn('creator_id');
            });
        }
    }
};
