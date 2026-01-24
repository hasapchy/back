<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels, Queueable;

    /**
     * Create a new event instance.
     */
    public function __construct(public ChatMessage $message)
    {
        $this->message->loadMissing([
            'chat:id,company_id',
            'user:id,name,surname,photo',
            'parent.user:id,name,surname,photo',
            'forwardedFrom.user:id,name,surname,photo',
        ]);
        
        \Log::info("[MessageSent Event] Создано событие для сообщения", [
            'message_id' => $this->message->id,
            'chat_id' => $this->message->chat_id,
            'user_id' => $this->message->user_id,
            'body' => substr($this->message->body ?? '', 0, 50),
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channel = "company.{$this->message->chat->company_id}.chat.{$this->message->chat_id}";
        \Log::info("[MessageSent Event] Broadcasting на канал: {$channel}");
        
        return [
            new PrivateChannel($channel),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    public function broadcastWith(): array
    {
        $parent = null;
        if ($this->message->parent_id && $this->message->relationLoaded('parent')) {
            $parentMessage = $this->message->parent;
            $parent = [
                'id' => (int) $parentMessage->id,
                'body' => $parentMessage->body,
                'files' => $parentMessage->files,
                'user' => $parentMessage->relationLoaded('user') ? [
                    'id' => (int) $parentMessage->user->id,
                    'name' => $parentMessage->user->name,
                    'surname' => $parentMessage->user->surname ?? null,
                    'photo' => $parentMessage->user->photo ?? null,
                ] : null,
            ];
        }

        $forwardedFrom = null;
        if ($this->message->forwarded_from_message_id && $this->message->relationLoaded('forwardedFrom')) {
            $forwardedMessage = $this->message->forwardedFrom;
            $forwardedFrom = [
                'id' => (int) $forwardedMessage->id,
                'body' => $forwardedMessage->body,
                'files' => $forwardedMessage->files,
                'user' => $forwardedMessage->relationLoaded('user') ? [
                    'id' => (int) $forwardedMessage->user->id,
                    'name' => $forwardedMessage->user->name,
                    'surname' => $forwardedMessage->user->surname ?? null,
                    'photo' => $forwardedMessage->user->photo ?? null,
                ] : null,
                'created_at' => $forwardedMessage->created_at?->toDateTimeString(),
            ];
        }

        return [
            'id' => $this->message->id,
            'chat_id' => $this->message->chat_id,
            'body' => $this->message->body,
            'files' => $this->message->files,
            'parent_id' => $this->message->parent_id,
            'parent' => $parent,
            'forwarded_from_message_id' => $this->message->forwarded_from_message_id,
            'forwarded_from' => $forwardedFrom,
            'user' => [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
                'surname' => $this->message->user->surname ?? null,
                'photo' => $this->message->user->photo ?? null,
            ],
            'created_at' => $this->message->created_at->toDateTimeString(),
        ];
    }
}
