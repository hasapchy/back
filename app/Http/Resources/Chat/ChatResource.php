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
        return [
            'id' => (int) $this->id,
            'company_id' => (int) $this->company_id,
            'type' => $this->type,
            'direct_key' => $this->direct_key,
            'title' => $this->title,
            'created_by' => $this->created_by,
            'last_message_at' => $this->last_message_at?->toDateTimeString(),
            'is_archived' => (bool) $this->is_archived,
            'avatar' => $this->avatar,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}


