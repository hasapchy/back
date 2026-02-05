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
        Schema::table('chat_participants', function (Blueprint $table) {
            // Составной индекс для быстрого подсчёта непрочитанных сообщений
            $table->index(['user_id', 'chat_id', 'last_read_message_id'], 'idx_user_chat_read');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // Индекс для быстрого поиска последних сообщений (DESC для ORDER BY id DESC)
            $table->index(['chat_id', 'id'], 'idx_chat_id_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->dropIndex('idx_user_chat_read');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('idx_chat_id_id');
        });
    }
};
