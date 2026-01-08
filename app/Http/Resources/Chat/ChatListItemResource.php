<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for chat list items returned by GET /chats.
 * Input is an array built by ChatService::listChats() (legacy-compatible).
 */
class ChatListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // This resource wraps already-prepared payload; just return it.
        /** @var array<string, mixed> $data */
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return $data;
    }
}


