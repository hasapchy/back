<?php

namespace App\Http\Resources\Chat;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->resource;
        if (!$message instanceof ChatMessage) {
            return [];
        }
        $parent = null;
        if ($message->parent_id && $message->relationLoaded('parent')) {
            $parentMessage = $message->parent;
            $parent = [
                'id' => (int) $parentMessage->id,
                'body' => $parentMessage->body,
                'files' => $parentMessage->files,
                'user' => $this->when(
                    $parentMessage->relationLoaded('user'),
                    fn () => [
                        'id' => (int) $parentMessage->user->id,
                        'name' => $parentMessage->user->name,
                        'surname' => $parentMessage->user->surname ?? null,
                        'photo' => $parentMessage->user->photo ?? null,
                    ]
                ),
            ];
        }

        $forwardedFrom = null;
        if ($message->forwarded_from_message_id && $message->relationLoaded('forwardedFrom')) {
            $forwardedMessage = $message->forwardedFrom;
            $forwardedFrom = [
                'id' => (int) $forwardedMessage->id,
                'body' => $forwardedMessage->body,
                'files' => $forwardedMessage->files,
                'user' => $this->when(
                    $forwardedMessage->relationLoaded('user'),
                    fn () => [
                        'id' => (int) $forwardedMessage->user->id,
                        'name' => $forwardedMessage->user->name,
                        'surname' => $forwardedMessage->user->surname ?? null,
                        'photo' => $forwardedMessage->user->photo ?? null,
                    ]
                ),
                'created_at' => $forwardedMessage->created_at?->toDateTimeString(),
            ];
        }

        return [
            'id' => (int) $message->id,
            'chat_id' => (int) $message->chat_id,
            'creator_id' => (int) $message->creator_id,
            'body' => $message->body,
            'files' => $message->files,
            'parent_id' => $message->parent_id,
            'parent' => $parent,
            'forwarded_from_message_id' => $message->forwarded_from_message_id,
            'forwarded_from' => $forwardedFrom,
            'user' => $this->when(
                $message->relationLoaded('user'),
                fn () => [
                    'id' => (int) $message->user->id,
                    'name' => $message->user->name,
                    'surname' => $message->user->surname ?? null,
                    'photo' => $message->user->photo ?? null,
                ]
            ),
            'is_edited' => (bool) ($message->is_edited ?? false),
            'edited_at' => $message->edited_at?->toDateTimeString(),
            'is_system' => (bool) ($message->is_system ?? false),
            'created_at' => $message->created_at?->toDateTimeString(),
            'updated_at' => $message->updated_at?->toDateTimeString(),
            'deleted_at' => $message->deleted_at?->toDateTimeString(),
            'reactions' => $this->when(
                $message->relationLoaded('reactions'),
                fn () => $message->reactions->map(fn ($r) => [
                    'emoji' => $r->emoji,
                    'creator_id' => (int) ($r->user_id ?? $r->creator_id ?? 0),
                    'user' => $r->relationLoaded('user') ? [
                        'id' => (int) $r->user->id,
                        'name' => $r->user->name,
                        'surname' => $r->user->surname ?? null,
                    ] : null,
                ])->values()->all()
            ),
        ];
    }
}


