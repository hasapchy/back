<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $companyId,
        public int $chatId,
        public int $userId,
        public int $lastReadMessageId
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
        return 'chat.read.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chatId,
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}


