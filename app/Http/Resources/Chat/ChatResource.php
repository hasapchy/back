<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = null;
        if ($this->type === 'group' && $this->created_by && $this->relationLoaded('creator')) {
            $creatorUser = $this->creator;
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
        if ($this->relationLoaded('pinnedMessage') && $this->pinnedMessage) {
            $pm = $this->pinnedMessage;
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
            'id' => (int) $this->id,
            'company_id' => (int) $this->company_id,
            'type' => $this->type,
            'direct_key' => $this->direct_key,
            'title' => $this->title,
            'created_by' => $this->created_by,
            'creator' => $creator,
            'last_message_at' => $this->last_message_at?->toDateTimeString(),
            'is_archived' => (bool) $this->is_archived,
            'avatar' => $this->avatar,
            'pinned_message' => $pinnedMessage,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}


