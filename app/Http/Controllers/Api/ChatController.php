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

class ChatController extends BaseController
{
    public function __construct(protected ChatService $chatService)
    {
    }

    public function index(IndexChatsRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        $payload = $this->chatService->listChats($companyId, $user);
        return ChatListItemResource::collection(collect($payload))->response();
    }

    public function general(): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        $chat = $this->chatService->ensureGeneralChat($companyId, $user);

        return (new ChatResource($chat))->response();
    }

    public function startDirect(StartDirectChatRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        $otherUserId = (int) $request->validated()['user_id'];
        if ($otherUserId === (int) $user->id) {
            return response()->json(['message' => 'Cannot create direct chat with yourself'], 422);
        }

        $central = config('tenancy.database.central_connection', 'central');
        $isOtherInCompany = DB::connection($central)->table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $otherUserId)
            ->exists();

        if (! $isOtherInCompany) {
            return response()->json(['message' => 'User is not in this company'], 403);
        }

        $chat = $this->chatService->startDirectChat($companyId, $user, $otherUserId);

        return (new ChatResource($chat))->response();
    }

    public function createGroup(CreateGroupChatRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        $data = $request->validated();
        $userIds = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));

        // Добавляем создателя в группу автоматически
        if (! in_array((int) $user->id, $userIds, true)) {
            $userIds[] = (int) $user->id;
        }

        // Проверяем membership всех участников в компании
        $central = config('tenancy.database.central_connection', 'central');
        $companyUserIds = DB::connection($central)->table('company_user')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $missing = array_values(array_diff($userIds, $companyUserIds));
        if (! empty($missing)) {
            return response()->json(['message' => 'Some users are not in this company', 'user_ids' => $missing], 403);
        }

        $chat = $this->chatService->createGroupChat($companyId, $user, (string) $data['title'], $userIds);

        return (new ChatResource($chat))->response()->setStatusCode(201);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

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

    public function markAsRead(MarkChatReadRequest $request, Chat $chat): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validated();
        $lastMessageId = isset($data['last_message_id']) ? (int) $data['last_message_id'] : null;

        $this->chatService->markAsRead($companyId, $user, $chat, $lastMessageId);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function storeMessage(StoreChatMessageRequest $request, Chat $chat): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($chat->type === 'general' && ! $this->hasPermission('chats_write_general', $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validated();
        $body = isset($data['body']) ? trim((string) $data['body']) : '';
        $files = $request->file('files', []);
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($body === '' && empty($files)) {
            return response()->json(['message' => 'Message body or files are required'], 422);
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

        return (new ChatMessageResource($message->load(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo', 'forwardedFrom.user:id,name,surname,photo'])))->response()->setStatusCode(201);
    }

    public function updateMessage(UpdateChatMessageRequest $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validated();
        $body = trim((string) $data['body']);

        $updatedMessage = $this->chatService->updateMessage(
            $companyId,
            $user,
            $chat,
            $message,
            $body
        );

        return (new ChatMessageResource($updatedMessage->load(['user:id,name,surname,photo', 'parent.user:id,name,surname,photo'])))->response();
    }

    public function deleteMessage(Chat $chat, ChatMessage $message): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->chatService->deleteMessage($companyId, $user, $chat, $message);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function setReaction(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $emoji = $request->input('emoji');
        if ($emoji !== null && $emoji !== '') {
            $emoji = is_string($emoji) ? trim($emoji) : '';
            $emoji = mb_substr($emoji, 0, 16) ?: null;
        }

        $reactions = $this->chatService->setReaction($companyId, $user, $chat, $message, $emoji);

        return response()->json(['data' => ['reactions' => $reactions]]);
    }

    public function forwardMessage(ForwardChatMessageRequest $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validated();
        $targetChatId = (int) $data['target_chat_id'];
        $targetChat = Chat::query()->findOrFail($targetChatId);

        if ((int) $targetChat->company_id !== $companyId) {
            return response()->json(['message' => 'Target chat does not belong to this company'], 403);
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
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        if ((int) $chat->company_id !== $companyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isParticipant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->chatService->deleteChat($companyId, $user, $chat);

        return response()->json(['data' => ['ok' => true]]);
    }
}
