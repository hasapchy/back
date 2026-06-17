<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewsReactionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param int $companyId
     * @param int $newsId
     * @param list<array<string, mixed>> $reactions
     */
    public function __construct(
        public int $companyId,
        public int $newsId,
        public array $reactions,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->companyId}.news.{$this->newsId}"),
        ];
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'news.reaction.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'news_id' => $this->newsId,
            'reactions' => $this->reactions,
        ];
    }
}
