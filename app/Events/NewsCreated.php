<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewsCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param int $companyId
     * @param array<string, mixed> $news
     */
    public function __construct(
        public int $companyId,
        public array $news,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->companyId}.news.feed"),
        ];
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'news.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'news' => $this->news,
        ];
    }
}
