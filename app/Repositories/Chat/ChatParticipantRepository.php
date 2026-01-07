<?php

namespace App\Repositories\Chat;

use App\Models\ChatParticipant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatParticipantRepository
{
    public function firstOrCreate(int $chatId, int $userId, string $role = 'member'): ChatParticipant
    {
        return ChatParticipant::query()->firstOrCreate([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], [
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    public function create(int $chatId, int $userId, string $role = 'member'): ChatParticipant
    {
        return ChatParticipant::query()->create([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, object>>
     */
    public function getParticipantsByChatIds(array $chatIds): Collection
    {
        if (empty($chatIds)) {
            return collect();
        }

        return DB::table('chat_participants')
            ->select(['chat_id', 'user_id', 'last_read_message_id'])
            ->whereIn('chat_id', $chatIds)
            ->get()
            ->groupBy('chat_id');
    }

    public function isParticipant(int $chatId, int $userId): bool
    {
        return ChatParticipant::query()
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function updateLastReadMessageId(int $chatId, int $userId, int $messageId): void
    {
        ChatParticipant::query()
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->update(['last_read_message_id' => $messageId]);
    }

    public function getLastReadMessageId(int $chatId, int $userId): int
    {
        $id = ChatParticipant::query()
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->value('last_read_message_id');

        return $id ? (int) $id : 0;
    }

    public function deleteByChatId(int $chatId): int
    {
        return ChatParticipant::query()->where('chat_id', $chatId)->delete();
    }
}


