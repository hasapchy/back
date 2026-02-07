<?php

namespace App\Repositories\Chat;

use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChatMessageRepository
{
    public function getMaxMessageId(int $chatId): ?int
    {
        $id = DB::table('chat_messages')->where('chat_id', $chatId)->max('id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, int> map chat_id => last_message_id
     */
    public function getLastMessageIdsByChatIds(array $chatIds)
    {
        if (empty($chatIds)) {
            return collect();
        }

        return DB::table('chat_messages')
            ->selectRaw('chat_id, MAX(id) as last_id')
            ->whereIn('chat_id', $chatIds)
            ->groupBy('chat_id')
            ->pluck('last_id', 'chat_id');
    }

    public function getMessagesByIds(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return new Collection();
        }

        return ChatMessage::query()
            ->with(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo', 'reactions.user:id,name,surname'])
            ->whereIn('id', $ids)
            ->get();
    }

    public function getMessages(int $chatId, ?int $afterId, int $limit): Collection
    {
        $query = ChatMessage::query()
            ->with(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo', 'reactions.user:id,name,surname'])
            ->where('chat_id', $chatId)
            ->orderBy('id');

        if ($afterId) {
            $query->where('id', '>', $afterId);
        }

        return $query->limit($limit)->get();
    }

    public function getLatestMessages(int $chatId, int $limit): Collection
    {
        // Get newest first, then reverse to chronological asc for UI
        $items = ChatMessage::query()
            ->with(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo', 'reactions.user:id,name,surname'])
            ->where('chat_id', $chatId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $items->reverse()->values();
    }

    public function getMessagesBeforeId(int $chatId, int $beforeId, int $limit): Collection
    {
        $items = ChatMessage::query()
            ->with(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo', 'reactions.user:id,name,surname'])
            ->where('chat_id', $chatId)
            ->where('id', '<', $beforeId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $items->reverse()->values();
    }

    public function messageExistsInChat(int $chatId, int $messageId): bool
    {
        return DB::table('chat_messages')
            ->where('chat_id', $chatId)
            ->where('id', $messageId)
            ->exists();
    }

    /**
     * Optimized unread counts for multiple chats:
     * counts messages with id > last_read_message_id for participant (user_id) and excludes own messages.
     *
     * @return array<int, int> map chat_id => unread_count
     */
    public function getUnreadCountsByChatIds(array $chatIds, int $userId): array
    {
        $chatIds = array_values(array_filter(array_map('intval', $chatIds)));
        if (empty($chatIds)) {
            return [];
        }

        return DB::table('chat_participants as cp')
            ->leftJoin('chat_messages as m', function ($join) use ($userId) {
                $join->on('m.chat_id', '=', 'cp.chat_id')
                    ->whereRaw('m.id > COALESCE(cp.last_read_message_id, 0)')
                    ->where('m.user_id', '!=', $userId);
            })
            ->where('cp.user_id', $userId)
            ->whereIn('cp.chat_id', $chatIds)
            ->groupBy('cp.chat_id')
            ->selectRaw('cp.chat_id, COUNT(m.id) as unread_count')
            ->pluck('unread_count', 'cp.chat_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    public function createMessage(int $chatId, int $userId, ?string $body, ?array $files, ?int $parentId = null, ?int $forwardedFromMessageId = null): ChatMessage
    {
        return ChatMessage::query()->create([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'body' => $body,
            'files' => $files,
            'parent_id' => $parentId,
            'forwarded_from_message_id' => $forwardedFromMessageId,
        ]);
    }

    public function updateMessage(int $messageId, int $userId, string $body): ChatMessage
    {
        $message = ChatMessage::query()->findOrFail($messageId);

        if ((int) $message->user_id !== $userId) {
            abort(403, 'You can only edit your own messages');
        }

        $message->update([
            'body' => $body,
            'is_edited' => true,
            'edited_at' => now(),
        ]);

        return $message->fresh();
    }

    public function deleteMessage(int $messageId, int $userId): bool
    {
        $message = ChatMessage::query()->findOrFail($messageId);

        if ((int) $message->user_id !== $userId) {
            abort(403, 'You can only delete your own messages');
        }

        return $message->update([
            'deleted_by' => $userId,
        ]) && $message->delete();
    }

    public function getMessageWithRelations(int $messageId): ?ChatMessage
    {
        return ChatMessage::query()
            ->with(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo', 'reactions.user:id,name,surname'])
            ->find($messageId);
    }

    public function getMessage(int $messageId): ?ChatMessage
    {
        return ChatMessage::query()->find($messageId);
    }

    public function deleteByChatId(int $chatId): int
    {
        return ChatMessage::query()->where('chat_id', $chatId)->delete();
    }
}


