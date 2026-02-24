<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatPinnedMessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $companyId,
        public int $chatId,
        public ?array $pinnedMessage
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->companyId}.chat.{$this->chatId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.pinned.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chatId,
            'pinned_message' => $this->pinnedMessage,
        ];
    }
}
