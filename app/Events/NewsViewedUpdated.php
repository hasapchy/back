<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewsViewedUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param int $companyId
     * @param int $newsId
     * @param list<array<string, mixed>> $viewedBy
     */
    public function __construct(
        public int $companyId,
        public int $newsId,
        public array $viewedBy,
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
        return 'news.viewed.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'news_id' => $this->newsId,
            'viewed_by' => $this->viewedBy,
        ];
    }
}
