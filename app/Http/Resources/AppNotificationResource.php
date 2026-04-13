<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $n = $this->resource;
        if (! $n instanceof AppNotification) {
            return [];
        }

        return [
            'id' => $n->id,
            'channel_key' => $n->channel_key,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data ?? [],
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
