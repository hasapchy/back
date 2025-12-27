<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Requests\Chat\CreateGroupChatRequest;
use App\Http\Requests\Chat\IndexChatsRequest;
use App\Http\Requests\Chat\StartDirectChatRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends BaseController
{
    public function index(IndexChatsRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();

        if (! $companyId) {
            return response()->json(['message' => 'X-Company-ID header is required'], 422);
        }

        // Общий чат на компанию (создаём лениво, если есть доступ к чатам)
        $generalChat = Chat::query()
            ->where('company_id', $companyId)
            ->where('type', 'general')
            ->first();

        if (! $generalChat) {
            $generalChat = Chat::query()->create([
                'company_id' => $companyId,
                'type' => 'general',
                'title' => 'Общий чат',
                'created_by' => $user->id,
            ]);
        }

        ChatParticipant::query()->firstOrCreate([
            'chat_id' => $generalChat->id,
            'user_id' => $user->id,
        ], [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $chats = Chat::query()
            ->select(['chats.*'])
            ->join('chat_participants', 'chat_participants.chat_id', '=', 'chats.id')
            ->where('chats.company_id', $companyId)
            ->where('chat_participants.user_id', $user->id)
            ->orderByRaw('chats.last_message_at IS NULL, chats.last_message_at DESC')
            ->orderBy('chats.id', 'desc')
            ->get();

        $chatIds = $chats->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $participantsByChatId = DB::table('chat_participants')
            ->select(['chat_id', 'user_id', 'last_read_message_id'])
            ->whereIn('chat_id', $chatIds)
            ->get()
            ->groupBy('chat_id');

        $lastMessageIds = DB::table('chat_messages')
            ->selectRaw('chat_id, MAX(id) as last_id')
            ->whereIn('chat_id', $chatIds)
            ->groupBy('chat_id')
            ->pluck('last_id', 'chat_id');

        $lastMessages = ChatMessage::query()
            ->whereIn('id', $lastMessageIds->values()->filter()->toArray())
            ->get()
            ->keyBy('chat_id');

        $chatsPayload = $chats->map(function (Chat $chat) use ($participantsByChatId, $lastMessages, $user) {
            $participants = $participantsByChatId->get($chat->id, collect());
            $myParticipant = $participants->firstWhere('user_id', $user->id);
            $lastReadId = $myParticipant?->last_read_message_id ? (int) $myParticipant->last_read_message_id : 0;

            $unreadCount = ChatMessage::query()
                ->where('chat_id', $chat->id)
                ->where('id', '>', $lastReadId)
                ->where('user_id', '!=', $user->id)
                ->count();

            $lastMessage = $lastMessages->get($chat->id);

            return [
                'id' => $chat->id,
                'company_id' => (int) $chat->company_id,
                'type' => $chat->type,
                'direct_key' => $chat->direct_key,
                'title' => $chat->title,
                'created_by' => $chat->created_by,
                'last_message_at' => $chat->last_message_at?->toDateTimeString(),
                'is_archived' => (bool) $chat->is_archived,
                'avatar' => $chat->avatar,
                'created_at' => $chat->created_at?->toDateTimeString(),
                'updated_at' => $chat->updated_at?->toDateTimeString(),
                'last_message' => $lastMessage ? [
                    'id' => $lastMessage->id,
                    'chat_id' => $lastMessage->chat_id,
                    'user_id' => $lastMessage->user_id,
                    'body' => $lastMessage->body,
                    'files' => $lastMessage->files,
                    'created_at' => $lastMessage->created_at?->toDateTimeString(),
                ] : null,
                'unread_count' => $unreadCount,
            ];
        })->values();

        return response()->json(['data' => $chatsPayload]);
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

        $otherUser = User::query()->findOrFail($otherUserId);

        $isOtherInCompany = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $otherUserId)
            ->exists();

        if (! $isOtherInCompany) {
            return response()->json(['message' => 'User is not in this company'], 403);
        }

        $directKey = $this->directKey($user->id, $otherUserId);

        $chat = Chat::query()
            ->where('company_id', $companyId)
            ->where('type', 'direct')
            ->where('direct_key', $directKey)
            ->first();

        if (! $chat) {
            $chat = Chat::query()->create([
                'company_id' => $companyId,
                'type' => 'direct',
                'direct_key' => $directKey,
                'created_by' => $user->id,
            ]);

            ChatParticipant::query()->create([
                'chat_id' => $chat->id,
                'user_id' => $user->id,
                'role' => 'member',
                'joined_at' => now(),
            ]);

            ChatParticipant::query()->create([
                'chat_id' => $chat->id,
                'user_id' => $otherUserId,
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }

        return response()->json(['data' => $chat]);
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
        $companyUserIds = DB::table('company_user')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $missing = array_values(array_diff($userIds, $companyUserIds));
        if (! empty($missing)) {
            return response()->json(['message' => 'Some users are not in this company', 'user_ids' => $missing], 403);
        }

        $chat = DB::transaction(function () use ($companyId, $user, $data, $userIds): Chat {
            $chat = Chat::query()->create([
                'company_id' => $companyId,
                'type' => 'group',
                'title' => trim($data['title']),
                'created_by' => $user->id,
            ]);

            foreach ($userIds as $id) {
                ChatParticipant::query()->create([
                    'chat_id' => $chat->id,
                    'user_id' => $id,
                    'role' => $id === (int) $user->id ? 'owner' : 'member',
                    'joined_at' => now(),
                ]);
            }

            return $chat;
        });

        return response()->json(['data' => $chat], 201);
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

        $query = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->orderBy('id');

        if ($afterId) {
            $query->where('id', '>', $afterId);
        }

        $messages = $query->limit($limit)->get();

        // Помечаем как прочитанное: если пользователь открыл чат, фиксируем последний id
        if ($messages->isNotEmpty()) {
            $lastId = (int) $messages->last()->id;
            ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->where('user_id', $user->id)
                ->update(['last_read_message_id' => $lastId]);
        }

        return response()->json(['data' => $messages]);
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

        if ($body === '' && empty($files)) {
            return response()->json(['message' => 'Message body or files are required'], 422);
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

        $message = ChatMessage::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'body' => $body === '' ? null : $body,
            'files' => empty($storedFiles) ? null : $storedFiles,
        ]);

        // Отправитель уже "прочитал" своё сообщение
        ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->update(['last_read_message_id' => $message->id]);

        $chat->forceFill(['last_message_at' => now()])->save();

        event(new MessageSent($message));

        return response()->json(['data' => $message], 201);
    }

    protected function directKey(int $userIdA, int $userIdB): string
    {
        $min = min($userIdA, $userIdB);
        $max = max($userIdA, $userIdB);

        return $min.':'.$max;
    }
}
