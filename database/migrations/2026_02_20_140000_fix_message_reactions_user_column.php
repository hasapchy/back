<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_reactions')) {
            return;
        }

        $hasCreatorId = Schema::hasColumn('message_reactions', 'creator_id');
        $hasUserId = Schema::hasColumn('message_reactions', 'user_id');

        if ($hasUserId && !$hasCreatorId) {
            return;
        }

        if ($hasCreatorId && !$hasUserId) {
            Schema::table('message_reactions', function (Blueprint $table) {
                $table->dropUnique(['message_id', 'creator_id']);
            });
            Schema::table('message_reactions', function (Blueprint $table) {
                $table->dropForeign(['creator_id']);
            });
            DB::statement('ALTER TABLE message_reactions CHANGE creator_id user_id BIGINT UNSIGNED NOT NULL');
            Schema::table('message_reactions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->unique(['message_id', 'user_id']);
            });
        }

        if (!$hasCreatorId && !$hasUserId) {
            Schema::table('message_reactions', function (Blueprint $table) {
                $table->foreignId('user_id')->after('message_id')->constrained()->cascadeOnDelete();
                $table->unique(['message_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
    }
};
