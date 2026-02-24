<?php

namespace App\Events;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Chat $chat,
        public User $user
    ) {
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->chat->company_id}.chat.{$this->chat->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat-typing';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'chat_id' => (int) $this->chat->id,
            'user_id' => (int) $this->user->id,
            'user' => [
                'id' => (int) $this->user->id,
                'name' => $this->user->name,
                'surname' => $this->user->surname ?? null,
            ],
        ];
    }
}
