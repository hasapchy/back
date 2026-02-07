<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

/** Событие обновления реакций на сообщение (добавление/удаление). */
class MessageReactionUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels, Queueable;

    public function __construct(
        public ChatMessage $message,
        public array $reactions
    ) {
        $this->message->loadMissing('chat:id,company_id');
    }

    public function broadcastOn(): array
    {
        $channel = "company.{$this->message->chat->company_id}.chat.{$this->message->chat_id}";
        return [new PrivateChannel($channel)];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.reaction';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => (int) $this->message->id,
            'chat_id' => (int) $this->message->chat_id,
            'reactions' => $this->reactions,
        ];
    }
}
