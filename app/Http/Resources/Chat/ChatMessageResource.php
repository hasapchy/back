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
        return [
            'id' => (int) $this->id,
            'chat_id' => (int) $this->chat_id,
            'user_id' => (int) $this->user_id,
            'body' => $this->body,
            'files' => $this->files,
            'parent_id' => $this->parent_id,
            'is_edited' => (bool) ($this->is_edited ?? false),
            'edited_at' => $this->edited_at?->toDateTimeString(),
            'is_system' => (bool) ($this->is_system ?? false),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }
}


