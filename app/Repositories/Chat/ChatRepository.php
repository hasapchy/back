<?php

namespace App\Repositories\Chat;

use App\Models\Chat;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class ChatRepository extends BaseRepository
{
    public function findGeneralChat(int $companyId): ?Chat
    {
        return Chat::query()
            ->where('company_id', $companyId)
            ->where('type', 'general')
            ->first();
    }

    public function createGeneralChat(int $companyId, int $createdByUserId, string $title = 'Общий чат'): Chat
    {
        return Chat::query()->create([
            'company_id' => $companyId,
            'type' => 'general',
            'title' => $title,
            'created_by' => $createdByUserId,
        ]);
    }

    public function getChatsForUser(int $companyId, int $userId): Collection
    {
        return Chat::query()
            ->select(['chats.*'])
            ->join('chat_participants', 'chat_participants.chat_id', '=', 'chats.id')
            ->where('chats.company_id', $companyId)
            ->where('chat_participants.user_id', $userId)
            ->orderByRaw('chats.last_message_at IS NULL, chats.last_message_at DESC')
            ->orderBy('chats.id', 'desc')
            ->get();
    }

    public function findDirectChatByKey(int $companyId, string $directKey): ?Chat
    {
        return Chat::query()
            ->where('company_id', $companyId)
            ->where('type', 'direct')
            ->where('direct_key', $directKey)
            ->first();
    }

    public function createDirectChat(int $companyId, int $createdByUserId, string $directKey): Chat
    {
        return Chat::query()->create([
            'company_id' => $companyId,
            'type' => 'direct',
            'direct_key' => $directKey,
            'created_by' => $createdByUserId,
        ]);
    }

    public function createGroupChat(int $companyId, int $createdByUserId, string $title): Chat
    {
        return Chat::query()->create([
            'company_id' => $companyId,
            'type' => 'group',
            'title' => $title,
            'created_by' => $createdByUserId,
        ]);
    }
}


