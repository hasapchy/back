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
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member'); // owner, member
            // FK на chat_messages нельзя создавать здесь, т.к. chat_messages создаётся в следующей миграции.
            // Добавим FK отдельной миграцией после создания chat_messages.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('joined_at');
            $table->index('user_id'); // Для поиска всех чатов пользователя
            $table->timestamp('muted_until')->nullable(); // Мьют участника
            $table->json('settings')->nullable(); // Настройки уведомлений и т.д.
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};
