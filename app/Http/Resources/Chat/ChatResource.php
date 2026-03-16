<?php

namespace App\Http\Resources\Chat;

use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $chat = $this->resource;
        if (!$chat instanceof Chat) {
            return [];
        }
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

        $pinnedMessage = null;
        if ($chat->relationLoaded('pinnedMessage') && $chat->pinnedMessage) {
            $pm = $chat->pinnedMessage;
            $pinnedMessage = [
                'id' => (int) $pm->id,
                'body' => $pm->body,
                'created_at' => $pm->created_at?->toDateTimeString(),
                'user' => $pm->relationLoaded('user') && $pm->user ? [
                    'id' => (int) $pm->user->id,
                    'name' => $pm->user->name,
                    'surname' => $pm->user->surname ?? null,
                ] : null,
            ];
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
            'pinned_message' => $pinnedMessage,
            'created_at' => $chat->created_at?->toDateTimeString(),
            'updated_at' => $chat->updated_at?->toDateTimeString(),
        ];
    }
}


