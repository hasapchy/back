<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    /**
     * Keep compatible with legacy "messages" endpoint (it returned full model arrays).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parent = null;
        if ($this->parent_id && $this->relationLoaded('parent')) {
            $parentMessage = $this->parent;
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
        if ($this->forwarded_from_message_id && $this->relationLoaded('forwardedFrom')) {
            $forwardedMessage = $this->forwardedFrom;
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
            'id' => (int) $this->id,
            'chat_id' => (int) $this->chat_id,
            'creator_id' => (int) $this->creator_id,
            'body' => $this->body,
            'files' => $this->files,
            'parent_id' => $this->parent_id,
            'parent' => $parent,
            'forwarded_from_message_id' => $this->forwarded_from_message_id,
            'forwarded_from' => $forwardedFrom,
            'user' => $this->when(
                $this->relationLoaded('user'),
                fn () => [
                    'id' => (int) $this->user->id,
                    'name' => $this->user->name,
                    'surname' => $this->user->surname ?? null,
                    'photo' => $this->user->photo ?? null,
                ]
            ),
            'is_edited' => (bool) ($this->is_edited ?? false),
            'edited_at' => $this->edited_at?->toDateTimeString(),
            'is_system' => (bool) ($this->is_system ?? false),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
            'reactions' => $this->when(
                $this->relationLoaded('reactions'),
                fn () => $this->reactions->map(fn ($r) => [
                    'emoji' => $r->emoji,
                    'creator_id' => (int) $r->creator_id,
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


