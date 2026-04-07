<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Chat\ChatListItemResource;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Http\Resources\Chat\ChatResource;
use App\Http\Requests\Chat\CreateGroupChatRequest;
use App\Http\Requests\Chat\IndexChatsRequest;
use App\Http\Requests\Chat\MarkChatReadRequest;
use App\Http\Requests\Chat\StartDirectChatRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\UpdateChatMessageRequest;
use App\Http\Requests\Chat\ForwardChatMessageRequest;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Chat\ChatService;
use App\Services\PushNotificationSender;
use Illuminate\Support\Str;

class ChatController extends BaseController
{
    public function __construct(
        protected ChatService $chatService,
        private readonly PushNotificationSender $pushNotificationSender,
    ) {
    }

    /**
     * @return array{0: \App\Models\User, 1: int}
     */
    protected function requireUserAndCompany(): array
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if (! $companyId) {
            abort(422, 'Company context is required');
        }
        return [$user, $companyId];
    }

    /**
     * @return array{0: \App\Models\User, 1: int}
     */
    protected function requireChatAccess(Chat $chat): array
    {
        [$user, $companyId] = $this->requireUserAndCompany();
        if ((int) $chat->company_id !== $companyId) {
            abort(403, 'Forbidden');
        }
        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            abort(403, 'Forbidden');
        }
        return [$user, $companyId];
    }

    public function index(IndexChatsRequest $request): JsonResponse
    {
        [$user, $companyId] = $this->requireUserAndCompany();

        $payload = $this->chatService->listChats($companyId, $user);
        return ChatListItemResource::collection(collect($payload))->response();
    }

    public function general(): JsonResponse
    {
        [$user, $companyId] = $this->requireUserAndCompany();

        $chat = $this->chatService->ensureGeneralChat($companyId, $user);

        return (new ChatResource($chat))->response();
    }

    public function startDirect(StartDirectChatRequest $request): JsonResponse
    {
        [$user, $companyId] = $this->requireUserAndCompany();

        $otherUserId = (int) $request->validated()['creator_id'];
        if ($otherUserId === (int) $user->id) {
            return $this->errorResponse('Cannot create direct chat with yourself', 422);
        }

        $isOtherInCompany = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $otherUserId)
            ->exists();

        if (! $isOtherInCompany) {
            return $this->errorResponse('User is not in this company', 403);
        }

        $chat = $this->chatService->startDirectChat($companyId, $user, $otherUserId);

        return (new ChatResource($chat))->response();
    }

    public function createGroup(CreateGroupChatRequest $request): JsonResponse
    {
        [$user, $companyId] = $this->requireUserAndCompany();

        $data = $request->validated();
        $userIds = array_values(array_unique(array_map('intval', $data['creator_ids'] ?? [])));

        // Добавляем создателя в группу автоматически
        if (! in_array((int) $user->id, $userIds, true)) {
            $userIds[] = (int) $user->id;
        }

        // Проверяем membership всех участников в компании
        $companyUserIds = DB::table('company_user')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $missing = array_values(array_diff($userIds, $companyUserIds));
        if (! empty($missing)) {
            return $this->errorResponse('Some users are not in this company', 403);
        }

        $chat = $this->chatService->createGroupChat($companyId, $user, (string) $data['title'], $userIds);

        return (new ChatResource($chat))->response()->setStatusCode(201);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min(200, $limit));
        $afterId = $request->query('after_id');
        $afterId = $afterId !== null ? (int) $afterId : null;
        $beforeId = $request->query('before_id');
        $beforeId = $beforeId !== null ? (int) $beforeId : null;
        $tail = $request->boolean('tail', false);

        $messages = $this->chatService->getMessages(
            companyId: $companyId,
            user: $user,
            chat: $chat,
            limit: $limit,
            afterId: $afterId,
            beforeId: $beforeId,
            tail: $tail
        );

        return ChatMessageResource::collection($messages)->response();
    }

    public function searchMessages(Request $request, Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $q = $request->query('q', '');
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(100, $limit));

        $messages = $this->chatService->searchMessages($companyId, $user, $chat, $q, $limit);

        return ChatMessageResource::collection($messages)->response();
    }

    public function markAsRead(MarkChatReadRequest $request, Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $data = $request->validated();
        $lastMessageId = isset($data['last_message_id']) ? (int) $data['last_message_id'] : null;

        $this->chatService->markAsRead($companyId, $user, $chat, $lastMessageId);

        return $this->successResponse(['ok' => true]);
    }

    public function typing(Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $this->chatService->sendTyping($companyId, $user, $chat);

        return $this->successResponse(['ok' => true]);
    }

    public function storeMessage(StoreChatMessageRequest $request, Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        if ($chat->type === 'general' && ! $this->hasPermission('chats_write_general', $user)) {
            return $this->successResponse(['message' => 'Forbidden'], null, 403);
        }

        $data = $request->validated();
        $body = isset($data['body']) ? trim((string) $data['body']) : '';
        $files = $request->file('files', []);
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($body === '' && empty($files)) {
            return $this->successResponse(['message' => 'Message body or files are required'], null, 422);
        }

        $message = $this->chatService->storeMessage(
            $companyId,
            $user,
            $chat,
            $body,
            is_array($files) ? $files : [],
            $this->hasPermission('chats_write_general', $user),
            $parentId
        );

        $this->sendNewMessagePush($chat, $message, $user);

        return (new ChatMessageResource($message->load(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo'])))->response()->setStatusCode(201);
    }

    private function sendNewMessagePush(Chat $chat, ChatMessage $message, $sender): void
    {
        try {
            $recipientIds = ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->where('user_id', '!=', (int) $sender->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->toArray();

            if ($recipientIds === []) {
                return;
            }

            $senderName = trim((string) ($sender->name ?? '').' '.(string) ($sender->surname ?? ''));
            if ($senderName === '') {
                $senderName = 'Unknown sender';
            }

            $messagePreview = trim((string) ($message->body ?? ''));
            if ($messagePreview === '') {
                $messagePreview = 'Attachment';
            }
            $messagePreview = Str::limit($messagePreview, 140);

            $chatTitle = trim((string) ($chat->title ?? ''));
            $pushTitle = $chat->type === 'direct'
                ? $senderName
                : ($chatTitle !== '' ? $chatTitle : 'Chat');

            $pushBody = $chat->type === 'direct'
                ? $messagePreview
                : $senderName.': '.$messagePreview;

            $this->pushNotificationSender->sendToUserIds(
                $recipientIds,
                $pushTitle,
                $pushBody,
                [
                    'type' => 'chat_new_message',
                    'chat_id' => (string) $chat->id,
                    'message_id' => (string) $message->id,
                    'chat_type' => (string) $chat->type,
                    'sender_id' => (string) $sender->id,
                    'sender_name' => $senderName,
                ]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function updateMessage(UpdateChatMessageRequest $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $data = $request->validated();
        $body = trim((string) $data['body']);
        $files = isset($data['files']) && is_array($data['files']) ? $data['files'] : null;

        $updatedMessage = $this->chatService->updateMessage(
            $companyId,
            $user,
            $chat,
            $message,
            $body,
            $files
        );

        return (new ChatMessageResource($updatedMessage->load(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo'])))->response();
    }

    public function deleteMessage(Chat $chat, ChatMessage $message): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $this->chatService->deleteMessage($companyId, $user, $chat, $message);

        return $this->successResponse(['ok' => true]);
    }

    public function setReaction(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $emoji = $request->input('emoji');
        $emoji = is_string($emoji) ? mb_substr(trim($emoji), 0, 16) : null;
        if ($emoji === '') {
            $emoji = null;
        }

        $reactions = $this->chatService->setReaction($companyId, $user, $chat, $message, $emoji);

        return $this->successResponse(['reactions' => $reactions]);
    }

    public function pinMessage(Chat $chat, ChatMessage $message): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $updated = $this->chatService->pinMessage($companyId, $user, $chat, $message);
        return (new ChatResource($updated))->response();
    }

    public function unpinMessage(Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $updated = $this->chatService->unpinMessage($companyId, $user, $chat);
        return (new ChatResource($updated))->response();
    }

    public function forwardMessage(ForwardChatMessageRequest $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $data = $request->validated();
        $targetChatId = (int) $data['target_chat_id'];
        $targetChat = Chat::query()->findOrFail($targetChatId);

        if ((int) $targetChat->company_id !== $companyId) {
            return $this->successResponse(['message' => 'Target chat does not belong to this company'], null, 403);
        }

        $hideSenderName = $request->boolean('hide_sender_name');

        $forwardedMessage = $this->chatService->forwardMessage(
            $companyId,
            $user,
            $chat,
            $message,
            $targetChat,
            $hideSenderName
        );

        return (new ChatMessageResource($forwardedMessage->load(['user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo'])))->response()->setStatusCode(201);
    }

    public function destroy(Chat $chat): JsonResponse
    {
        [$user, $companyId] = $this->requireChatAccess($chat);

        $this->chatService->deleteChat($companyId, $user, $chat);

        return $this->successResponse(['ok' => true]);
    }
}
