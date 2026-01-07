<?php

namespace App\Services\Chat;

use App\Events\ChatReadUpdated;
use App\Events\MessageSent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Repositories\Chat\ChatMessageRepository;
use App\Repositories\Chat\ChatParticipantRepository;
use App\Repositories\Chat\ChatRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;

class ChatService
{
    public function __construct(
        protected ChatRepository $chats,
        protected ChatParticipantRepository $participants,
        protected ChatMessageRepository $messages,
    ) {
    }

    /**
     * Returns the same payload shape as the legacy controller to avoid breaking the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listChats(int $companyId, User $user): array
    {
        // Ensure general chat exists and the user is a participant
        $generalChat = $this->chats->findGeneralChat($companyId);
        if (! $generalChat) {
            $generalChat = $this->chats->createGeneralChat($companyId, (int) $user->id, 'Общий чат');
        }

        $this->participants->firstOrCreate((int) $generalChat->id, (int) $user->id, 'member');

        $chats = $this->chats->getChatsForUser($companyId, (int) $user->id);
        $chatIds = $chats->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        // Load creators for group chats
        $groupChats = $chats->where('type', 'group');
        if ($groupChats->isNotEmpty()) {
            $groupChats->load('creator:id,name,surname,email');
        }

        $participantsByChatId = $this->participants->getParticipantsByChatIds($chatIds);
        $this->backfillMissingDirectKeys($companyId, $chats, $participantsByChatId);

        $lastMessageIds = $this->messages->getLastMessageIdsByChatIds($chatIds);
        $lastMessages = $this->messages
            ->getMessagesByIds($lastMessageIds->values()->filter()->toArray())
            ->keyBy('chat_id');

        $unreadCounts = $this->messages->getUnreadCountsByChatIds($chatIds, (int) $user->id);

        $userId = (int) $user->id;

        return $chats->map(function (Chat $chat) use ($lastMessages, $unreadCounts, $participantsByChatId, $userId) {
            $lastMessage = $lastMessages->get($chat->id);
            $unreadCount = (int) ($unreadCounts[(int) $chat->id] ?? 0);

            $myLastReadId = null;
            $peerLastReadId = null;
            if ($chat->type === 'direct') {
                $parts = $participantsByChatId->get($chat->id, collect());
                $my = $parts->firstWhere('user_id', $userId);
                $peer = $parts->firstWhere('user_id', '!=', $userId);

                $myLastReadId = $my?->last_read_message_id ? (int) $my->last_read_message_id : 0;
                $peerLastReadId = $peer?->last_read_message_id ? (int) $peer->last_read_message_id : 0;
            }

            // Creator info for group chats
            $creator = null;
            if ($chat->type === 'group' && $chat->created_by && $chat->relationLoaded('creator')) {
                $creatorUser = $chat->creator;
                if ($creatorUser) {
                    $creator = [
                        'id' => (int) $creatorUser->id,
                        'name' => $creatorUser->name,
                        'surname' => $creatorUser->surname,
                        'email' => $creatorUser->email,
                    ];
                }
            }

            return [
                'id' => (int) $chat->id,
                'company_id' => (int) $chat->company_id,
                'type' => $chat->type,
                'direct_key' => $chat->direct_key,
                'title' => $chat->title,
                'created_by' => $chat->created_by,
                'creator' => $creator,
                'last_message_at' => $chat->last_message_at?->toDateTimeString(),
                'is_archived' => (bool) $chat->is_archived,
                'avatar' => $chat->avatar,
                'created_at' => $chat->created_at?->toDateTimeString(),
                'updated_at' => $chat->updated_at?->toDateTimeString(),
                'last_message' => $lastMessage ? [
                    'id' => (int) $lastMessage->id,
                    'chat_id' => (int) $lastMessage->chat_id,
                    'user_id' => (int) $lastMessage->user_id,
                    'body' => $lastMessage->body,
                    'files' => $lastMessage->files,
                    'created_at' => $lastMessage->created_at?->toDateTimeString(),
                ] : null,
                'unread_count' => $unreadCount,
                // For direct chats: read receipts
                'my_last_read_message_id' => $myLastReadId,
                'peer_last_read_message_id' => $peerLastReadId,
            ];
        })->values()->toArray();
    }

    public function ensureGeneralChat(int $companyId, User $user): Chat
    {
        $chat = $this->chats->findGeneralChat($companyId);
        if (! $chat) {
            $chat = $this->chats->createGeneralChat($companyId, (int) $user->id, 'Общий чат');
        }

        $this->participants->firstOrCreate((int) $chat->id, (int) $user->id, 'member');

        // Load creator for group chats
        if ($chat->type === 'group' && $chat->created_by) {
            $chat->load('creator:id,name,surname,email');
        }

        return $chat;
    }

    public function startDirectChat(int $companyId, User $user, int $otherUserId): Chat
    {
        $directKey = $this->directKey((int) $user->id, $otherUserId);

        $chat = $this->chats->findDirectChatByKey($companyId, $directKey);
        if ($chat) {
            return $chat;
        }

        return DB::transaction(function () use ($companyId, $user, $otherUserId, $directKey) {
            $chat = $this->chats->createDirectChat($companyId, (int) $user->id, $directKey);

            $this->participants->create((int) $chat->id, (int) $user->id, 'member');
            $this->participants->create((int) $chat->id, $otherUserId, 'member');

            return $chat;
        });
    }

    /**
     * @param array<int, int> $userIds
     */
    public function createGroupChat(int $companyId, User $user, string $title, array $userIds): Chat
    {
        $title = trim($title);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (! in_array((int) $user->id, $userIds, true)) {
            $userIds[] = (int) $user->id;
        }

        return DB::transaction(function () use ($companyId, $user, $title, $userIds) {
            $chat = $this->chats->createGroupChat($companyId, (int) $user->id, $title);

            foreach ($userIds as $id) {
                $this->participants->create(
                    (int) $chat->id,
                    (int) $id,
                    ((int) $id === (int) $user->id) ? 'owner' : 'member'
                );
            }

            // Load creator
            $chat->load('creator:id,name,surname,email');

            return $chat;
        });
    }

    public function getMessagesAndMarkRead(int $companyId, User $user, Chat $chat, int $limit, ?int $afterId)
    {
        return $this->getMessages(
            companyId: $companyId,
            user: $user,
            chat: $chat,
            limit: $limit,
            afterId: $afterId,
            beforeId: null,
            tail: false
        );
    }

    public function getMessages(
        int $companyId,
        User $user,
        Chat $chat,
        int $limit,
        ?int $afterId,
        ?int $beforeId,
        bool $tail
    ) {
        if ($beforeId) {
            // Infinite-scroll: load older messages, do NOT affect read state.
            return $this->messages->getMessagesBeforeId((int) $chat->id, $beforeId, $limit);
        }

        if ($afterId) {
            // Polling: load newer messages after a given id (chronological asc)
            $messages = $this->messages->getMessages((int) $chat->id, $afterId, $limit);
            // For "after" mode we can advance read state when user is actively consuming the stream.
            if ($messages->isNotEmpty()) {
                $lastId = (int) $messages->last()->id;
                $this->participants->updateLastReadMessageId((int) $chat->id, (int) $user->id, $lastId);
            }
            return $messages;
        }

        // Default open-chat load: fetch last N messages if tail=true, otherwise keep legacy behavior.
        $messages = $tail
            ? $this->messages->getLatestMessages((int) $chat->id, $limit)
            : $this->messages->getMessages((int) $chat->id, null, $limit);

        if ($messages->isNotEmpty()) {
            $lastId = (int) $messages->last()->id;
            $this->participants->updateLastReadMessageId((int) $chat->id, (int) $user->id, $lastId);
        }

        return $messages;
    }

    public function markAsRead(int $companyId, User $user, Chat $chat, ?int $lastMessageId = null): void
    {
        $messageId = $lastMessageId ?: $this->messages->getMaxMessageId((int) $chat->id);
        if (! $messageId) {
            return;
        }

        if ($lastMessageId) {
            $exists = $this->messages->messageExistsInChat((int) $chat->id, (int) $lastMessageId);
            if (! $exists) {
                return;
            }
        }

        $chatId = (int) $chat->id;
        $userId = (int) $user->id;
        $newId = (int) $messageId;

        $oldId = $this->participants->getLastReadMessageId($chatId, $userId);
        if ($newId <= $oldId) {
            return;
        }

        $this->participants->updateLastReadMessageId($chatId, $userId, $newId);

        event(new ChatReadUpdated(
            companyId: (int) $chat->company_id,
            chatId: $chatId,
            userId: $userId,
            lastReadMessageId: $newId
        ));
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    public function storeMessage(
        int $companyId,
        User $user,
        Chat $chat,
        ?string $body,
        array $files,
        bool $canWriteGeneral = true,
    ): ChatMessage {
        $body = $body !== null ? trim((string) $body) : '';

        if ($chat->type === 'general' && ! $canWriteGeneral) {
            abort(403, 'Forbidden');
        }

        if ($body === '' && empty($files)) {
            throw new HttpResponseException(
                response()->json(['message' => 'Message body or files are required'], 422)
            );
        }

        $storedFiles = [];
        foreach ($files as $file) {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('chats/'.$chat->id, $filename, 'public');

            $storedFiles[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        $message = $this->messages->createMessage(
            (int) $chat->id,
            (int) $user->id,
            $body === '' ? null : $body,
            empty($storedFiles) ? null : $storedFiles
        );

        // Sender has "read" their own message
        $this->participants->updateLastReadMessageId((int) $chat->id, (int) $user->id, (int) $message->id);

        $chat->forceFill(['last_message_at' => now()])->save();

        // Отправляем событие через broadcasting (неблокирующе)
        try {
            event(new MessageSent($message));
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            \Log::error('Failed to broadcast MessageSent event', [
                'message_id' => $message->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $message;
    }

    protected function directKey(int $userIdA, int $userIdB): string
    {
        $min = min($userIdA, $userIdB);
        $max = max($userIdA, $userIdB);

        return $min.':'.$max;
    }

    protected function backfillMissingDirectKeys(int $companyId, $chats, $participantsByChatId): void
    {
        $directChatsWithoutKey = $chats->where('type', 'direct')->whereNull('direct_key');
        foreach ($directChatsWithoutKey as $directChat) {
            $parts = $participantsByChatId->get($directChat->id, collect())->pluck('user_id')->unique()->values();
            if ($parts->count() !== 2) {
                continue;
            }

            $newDirectKey = $this->directKey((int) $parts[0], (int) $parts[1]);

            $existingChat = $this->chats->findDirectChatByKey($companyId, $newDirectKey);
            if ($existingChat && (int) $existingChat->id !== (int) $directChat->id) {
                continue;
            }

            $directChat->direct_key = $newDirectKey;
            $directChat->save();
        }
    }

    public function deleteChat(int $companyId, User $user, Chat $chat): void
    {
        if ((int) $chat->company_id !== $companyId) {
            abort(403, 'Forbidden');
        }

        // Only creator can delete group chat
        if ($chat->type === 'group' && (int) $chat->created_by !== (int) $user->id) {
            abort(403, 'Only chat creator can delete the chat');
        }

        // Cannot delete general or direct chats
        if ($chat->type === 'general' || $chat->type === 'direct') {
            abort(422, 'Cannot delete general or direct chats');
        }

        DB::transaction(function () use ($chat) {
            // Delete all messages
            $this->messages->deleteByChatId((int) $chat->id);

            // Delete all participants
            $this->participants->deleteByChatId((int) $chat->id);

            // Delete chat
            $this->chats->delete((int) $chat->id);
        });
    }
}


