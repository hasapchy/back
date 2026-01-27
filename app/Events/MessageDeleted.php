<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels, Queueable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ChatMessage $message,
        public int $companyId,
        public int $chatId
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->companyId}.chat.{$this->chatId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'chat_id' => $this->chatId,
            'deleted_at' => $this->message->deleted_at?->toDateTimeString(),
        ];
    }
}
