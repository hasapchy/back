<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class ChatReadUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels, Queueable;

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


