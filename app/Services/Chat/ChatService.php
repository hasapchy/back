<?php

namespace App\Services\Chat;

use App\Events\ChatReadUpdated;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageReactionUpdated;
use App\Events\UserTyping;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\MessageReaction;
use App\Models\User;
use App\Repositories\Chat\ChatMessageRepository;
use App\Repositories\Chat\ChatParticipantRepository;
use App\Repositories\Chat\ChatRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
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
        $startTime = microtime(true);
        $userId = (int) $user->id;

        // Ensure general chat exists and the user is a participant
        $generalChat = $this->chats->findGeneralChat($companyId);
        if (!$generalChat) {
            $generalChat = $this->chats->createGeneralChat($companyId, $userId, 'Общий чат');
        }

        $this->participants->firstOrCreate((int) $generalChat->id, $userId, 'member');

        // Кэшируем базовый список чатов на 60 секунд
        $cacheKey = "chats:company:{$companyId}:user:{$userId}";
        $chats = Cache::remember($cacheKey, 60, function () use ($companyId, $userId) {
            return $this->chats->getChatsForUser($companyId, $userId);
        });

        $chatIds = $chats->pluck('id')->map(fn($id) => (int) $id)->toArray();

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

        $pinnedIds = $chats->pluck('pinned_message_id')->filter()->unique()->values()->toArray();
        $pinnedMessages = !empty($pinnedIds)
            ? $this->messages->getMessagesByIds($pinnedIds)->keyBy('id')
            : collect();

        $result = $chats->map(function (Chat $chat) use ($lastMessages, $unreadCounts, $participantsByChatId, $userId, $pinnedMessages) {
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
                    'creator_id' => (int) $lastMessage->creator_id,
                    'body' => $lastMessage->body,
                    'files' => $lastMessage->files,
                    'created_at' => $lastMessage->created_at?->toDateTimeString(),
                ] : null,
                'unread_count' => $unreadCount,
                'my_last_read_message_id' => $myLastReadId,
                'peer_last_read_message_id' => $peerLastReadId,
                'pinned_message' => $this->formatPinnedMessage($chat->pinned_message_id, $pinnedMessages),
            ];
        })->values()->toArray();

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        if ($elapsed > 100) {
            Log::channel('chat')->warning('Slow chats list', [
                'time_ms' => $elapsed,
                'company_id' => $companyId,
                'creator_id' => $user->id,
                'chats_count' => $chats->count(),
            ]);
        }

        return $result;
    }

    /**
     * @param \Illuminate\Support\Collection<int, ChatMessage> $pinnedMessages
     * @return array<string, mixed>|null
     */
    private function formatPinnedMessage(?int $pinnedMessageId, $pinnedMessages): ?array
    {
        if (!$pinnedMessageId) {
            return null;
        }
        $msg = $pinnedMessages->get($pinnedMessageId);
        if (!$msg) {
            return null;
        }
        $user = $msg->relationLoaded('user') ? $msg->user : null;
        return [
            'id' => (int) $msg->id,
            'body' => $msg->body,
            'created_at' => $msg->created_at?->toDateTimeString(),
            'user' => $user ? [
                'id' => (int) $user->id,
                'name' => $user->name,
                'surname' => $user->surname ?? null,
            ] : null,
        ];
    }

    public function ensureGeneralChat(int $companyId, User $user): Chat
    {
        $chat = $this->chats->findGeneralChat($companyId);
        if (!$chat) {
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
            $this->participants->firstOrCreate((int) $chat->id, (int) $user->id, 'member');
            $this->participants->firstOrCreate((int) $chat->id, $otherUserId, 'member');
            return $chat;
        }

        $chat = DB::transaction(function () use ($companyId, $user, $otherUserId, $directKey) {
            $chat = $this->chats->createDirectChat($companyId, (int) $user->id, $directKey);

            $this->participants->create((int) $chat->id, (int) $user->id, 'member');
            $this->participants->create((int) $chat->id, $otherUserId, 'member');

            return $chat;
        });

        // Инвалидируем кэш для обоих участников
        $this->invalidateChatListCache($companyId, (int) $chat->id);

        return $chat;
    }

    /**
     * @param array<int, int> $userIds
     */
    public function createGroupChat(int $companyId, User $user, string $title, array $userIds): Chat
    {
        $title = trim($title);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (!in_array((int) $user->id, $userIds, true)) {
            $userIds[] = (int) $user->id;
        }

        $chat = DB::transaction(function () use ($companyId, $user, $title, $userIds) {
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

        // Инвалидируем кэш для всех участников группового чата
        $this->invalidateChatListCache($companyId, (int) $chat->id);

        return $chat;
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatMessage>
     */
    public function searchMessages(int $companyId, User $user, Chat $chat, string $q, int $limit = 50)
    {
        return $this->messages->searchInChat((int) $chat->id, $q, $limit);
    }

    public function markAsRead(int $companyId, User $user, Chat $chat, ?int $lastMessageId = null): void
    {
        $messageId = $lastMessageId ?: $this->messages->getMaxMessageId((int) $chat->id);
        if (!$messageId) {
            return;
        }

        if ($lastMessageId) {
            $exists = $this->messages->messageExistsInChat((int) $chat->id, (int) $lastMessageId);
            if (!$exists) {
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

        $cacheKey = "chats:company:{$companyId}:user:{$userId}";
        Cache::forget($cacheKey);

        event(new ChatReadUpdated(
            companyId: (int) $chat->company_id,
            chatId: $chatId,
            userId: $userId,
            lastReadMessageId: $newId
        ));
    }

    public function sendTyping(int $companyId, User $user, Chat $chat): void
    {
        event(new UserTyping($chat, $user));
    }

    public function pinMessage(int $companyId, User $user, Chat $chat, ChatMessage $message): Chat
    {
        if ((int) $message->chat_id !== (int) $chat->id) {
            abort(422, 'Message does not belong to this chat');
        }
        $chat->update(['pinned_message_id' => $message->id]);
        $this->invalidateChatListCache($companyId, (int) $chat->id);
        $updated = $chat->fresh(['pinnedMessage.user:id,name,surname']);
        $pinnedPayload = $updated->pinnedMessage ? [
            'id' => (int) $updated->pinnedMessage->id,
            'body' => $updated->pinnedMessage->body,
            'created_at' => $updated->pinnedMessage->created_at?->toDateTimeString(),
            'user' => $updated->pinnedMessage->relationLoaded('user') && $updated->pinnedMessage->user ? [
                'id' => (int) $updated->pinnedMessage->user->id,
                'name' => $updated->pinnedMessage->user->name,
                'surname' => $updated->pinnedMessage->user->surname ?? null,
            ] : null,
        ] : null;
        try {
            event(new \App\Events\ChatPinnedMessageUpdated($companyId, (int) $chat->id, $pinnedPayload));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast ChatPinnedMessageUpdated', ['error' => $e->getMessage()]);
        }
        return $updated;
    }

    public function unpinMessage(int $companyId, User $user, Chat $chat): Chat
    {
        $chat->update(['pinned_message_id' => null]);
        $this->invalidateChatListCache($companyId, (int) $chat->id);
        try {
            event(new \App\Events\ChatPinnedMessageUpdated($companyId, (int) $chat->id, null));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast ChatPinnedMessageUpdated', ['error' => $e->getMessage()]);
        }
        return $chat->fresh();
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
        ?int $parentId = null,
    ): ChatMessage {
        $startTime = microtime(true);
        $body = $body !== null ? trim((string) $body) : '';

        if ($chat->type === 'general' && !$canWriteGeneral) {
            abort(403, 'Forbidden');
        }

        if ($body === '' && empty($files)) {
            throw new HttpResponseException(
                response()->json(['message' => 'Message body or files are required'], 422)
            );
        }

        // Validate parent message exists and belongs to the same chat
        if ($parentId) {
            $parentMessage = $this->messages->getMessage((int) $parentId);
            if (!$parentMessage || (int) $parentMessage->chat_id !== (int) $chat->id) {
                abort(422, 'Parent message not found or belongs to different chat');
            }
        }

        $storedFiles = [];
        foreach ($files as $file) {
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('chats/' . $chat->id, $filename, 'public');

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
            empty($storedFiles) ? null : $storedFiles,
            $parentId
        );

        // Load relations for broadcasting
        $message->load(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo']);

        // Sender has "read" their own message
        $this->participants->updateLastReadMessageId((int) $chat->id, (int) $user->id, (int) $message->id);

        $chat->forceFill(['last_message_at' => now()])->save();

        // Инвалидируем кэш списка чатов для всех участников
        $this->invalidateChatListCache($companyId, (int) $chat->id);

        // Отправляем событие через broadcasting (неблокирующе)
        try {
            event(new MessageSent($message));
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            Log::error('Failed to broadcast MessageSent event', [
                'message_id' => $message->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        if ($elapsed > 100) {
            Log::channel('chat')->warning('Slow message send', [
                'time_ms' => $elapsed,
                'chat_id' => $chat->id,
                'creator_id' => $user->id,
                'has_files' => !empty($storedFiles),
                'files_count' => count($storedFiles),
            ]);
        }

        return $message;
    }

    public function updateMessage(
        int $companyId,
        User $user,
        Chat $chat,
        ChatMessage $message,
        string $body,
        ?array $files = null
    ): ChatMessage {
        if ((int) $message->chat_id !== (int) $chat->id) {
            abort(422, 'Message does not belong to this chat');
        }

        if ($message->created_at && now()->diffInHours($message->created_at) > 72) {
            abort(422, 'Message can only be edited within 72 hours of creation');
        }

        $sanitizedFiles = null;
        if ($files !== null) {
            $existingPaths = collect($message->files ?? [])->pluck('path')->filter()->values()->all();
            $sanitizedFiles = collect($files)->filter(function ($f) use ($existingPaths) {
                $path = is_array($f) ? ($f['path'] ?? '') : '';
                return $path !== '' && in_array($path, $existingPaths, true);
            })->values()->all();
        }

        $updatedMessage = $this->messages->updateMessage((int) $message->id, (int) $user->id, $body, $sanitizedFiles);
        $updatedMessage->load(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo']);

        // Отправляем событие через broadcasting
        try {
            event(new MessageUpdated($updatedMessage));
        } catch (\Exception $e) {
            Log::error('Failed to broadcast MessageUpdated event', [
                'message_id' => $updatedMessage->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $updatedMessage;
    }

    public function deleteMessage(
        int $companyId,
        User $user,
        Chat $chat,
        ChatMessage $message
    ): void {
        if ((int) $message->chat_id !== (int) $chat->id) {
            abort(422, 'Message does not belong to this chat');
        }

        $userId = (int) $user->id;
        $isAuthor = (int) $message->creator_id === $userId;
        $canDeleteForEveryone = false;
        if (!$isAuthor && $chat->type === 'group') {
            $participant = $this->participants->getParticipant((int) $chat->id, $userId);
            $canDeleteForEveryone = $participant && in_array($participant->role, ['admin', 'owner'], true);
        }
        if (!$isAuthor && !$canDeleteForEveryone) {
            abort(403, 'You can only delete your own messages');
        }

        if ($isAuthor) {
            $this->messages->deleteMessage((int) $message->id, $userId);
        } else {
            $this->messages->deleteMessageAsAdmin((int) $message->id, $userId);
        }

        // If the message had attached files, delete them from storage
        if (!empty($message->files) && is_array($message->files)) {
            foreach ($message->files as $file) {
                if (isset($file['path'])) {
                    try {
                        \Illuminate\Support\Facades\Storage::delete($file['path']);
                    } catch (\Exception $e) {
                        Log::error('Failed to delete attached file on message delete', [
                            'message_id' => $message->id,
                            'file_path' => $file['path'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Отправляем событие через broadcasting
        try {
            event(new MessageDeleted($message, (int) $chat->company_id, (int) $chat->id));
        } catch (\Exception $e) {
            Log::error('Failed to broadcast MessageDeleted event', [
                'message_id' => $message->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }

    }

    public function forwardMessage(
        int $companyId,
        User $user,
        Chat $sourceChat,
        ChatMessage $message,
        Chat $targetChat,
        bool $hideSenderName = false
    ): ChatMessage {
        if ((int) $message->chat_id !== (int) $sourceChat->id) {
            abort(422, 'Message does not belong to source chat');
        }

        // Check if user is participant of target chat
        if (!$this->participants->isParticipant((int) $targetChat->id, (int) $user->id)) {
            abort(403, 'You are not a participant of the target chat');
        }

        // При скрытии отправителя создаём сообщение без ссылки на оригинал — выглядит как своё
        $forwardedFromMessageId = $hideSenderName ? null : (int) $message->id;

        $forwardedMessage = $this->messages->createMessage(
            (int) $targetChat->id,
            (int) $user->id,
            $message->body,
            $message->files,
            null,
            $forwardedFromMessageId
        );

        $forwardedMessage->load(['user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo']);

        // Sender has "read" their own message
        $this->participants->updateLastReadMessageId((int) $targetChat->id, (int) $user->id, (int) $forwardedMessage->id);

        $targetChat->forceFill(['last_message_at' => now()])->save();

        // Отправляем событие через broadcasting
        try {
            event(new MessageSent($forwardedMessage));
        } catch (\Exception $e) {
            Log::error('Failed to broadcast forwarded MessageSent event', [
                'message_id' => $forwardedMessage->id,
                'chat_id' => $targetChat->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $forwardedMessage;
    }

    protected function directKey(int $userIdA, int $userIdB): string
    {
        $min = min($userIdA, $userIdB);
        $max = max($userIdA, $userIdB);

        return $min . ':' . $max;
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

    /**
     * Установить или снять реакцию на сообщение (один эмодзи на пользователя).
     * @param string|null $emoji Один эмодзи (например "👍") или null чтобы снять реакцию.
     */
    public function setReaction(int $companyId, User $user, Chat $chat, ChatMessage $message, ?string $emoji): array
    {
        if ((int) $message->chat_id !== (int) $chat->id) {
            abort(422, 'Message does not belong to this chat');
        }

        $userId = (int) $user->id;
        $messageId = (int) $message->id;

        if ($emoji === null || $emoji === '') {
            MessageReaction::query()
                ->where('message_id', $messageId)
                ->where('user_id', $userId)
                ->delete();
        } else {
            $existing = MessageReaction::query()
                ->where('message_id', $messageId)
                ->where('user_id', $userId)
                ->where('emoji', $emoji)
                ->first();
            if ($existing) {
                $existing->delete();
            } else {
                MessageReaction::query()->updateOrInsert(
                    ['message_id' => $messageId, 'user_id' => $userId],
                    ['emoji' => $emoji, 'updated_at' => now()]
                );
            }
        }

        $reactions = $this->formatReactionsForMessage($messageId);

        try {
            event(new MessageReactionUpdated($message->fresh(), $reactions));
        } catch (\Exception $e) {
            Log::error('Failed to broadcast MessageReactionUpdated', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

        return $reactions;
    }

    /** Формат реакций для API: [{ emoji, creator_id }]. */
    protected function formatReactionsForMessage(int $messageId): array
    {
        return MessageReaction::query()
            ->where('message_id', $messageId)
            ->with('user:id,name,surname')
            ->get()
            ->map(fn (MessageReaction $r) => [
                'emoji' => $r->emoji,
                'creator_id' => (int) $r->user_id,
                'user' => $r->relationLoaded('user') ? [
                    'id' => (int) $r->user->id,
                    'name' => $r->user->name,
                    'surname' => $r->user->surname ?? null,
                ] : null,
            ])
            ->values()
            ->all();
    }

    public function deleteChat(int $companyId, User $user, Chat $chat): void
    {
        if ($chat->type === 'group' && (int) $chat->created_by !== (int) $user->id) {
            abort(403, 'Only chat creator can delete the chat');
        }

        // Cannot delete general or direct chats
        if ($chat->type === 'general' || $chat->type === 'direct') {
            abort(422, 'Cannot delete general or direct chats');
        }

        // Инвалидируем кэш перед удалением
        $this->invalidateChatListCache($companyId, (int) $chat->id);

        DB::transaction(function () use ($chat) {
            // Delete all messages
            $this->messages->deleteByChatId((int) $chat->id);

            // Delete all participants
            $this->participants->deleteByChatId((int) $chat->id);

            // Delete chat
            $this->chats->delete((int) $chat->id);
        });
    }

    /**
     * Инвалидация кэша списка чатов для всех участников
     */
    protected function invalidateChatListCache(int $companyId, int $chatId): void
    {
        try {
            // Получаем всех участников чата
            $participantUserIds = $this->participants->getParticipantsByChatIds([$chatId])
                ->get($chatId, collect())
                ->pluck('user_id')
                ->unique()
                ->toArray();

            // Очищаем кэш для каждого участника
            foreach ($participantUserIds as $userId) {
                $cacheKey = "chats:company:{$companyId}:user:{$userId}";
                Cache::forget($cacheKey);
            }
        } catch (\Exception $e) {
            // Не прерываем выполнение если кэш недоступен
            Log::warning('Failed to invalidate chat list cache', [
                'chat_id' => $chatId,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


