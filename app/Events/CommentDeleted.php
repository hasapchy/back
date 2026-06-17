<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentDeleted implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param int $companyId
     * @param int $newsId
     * @param int $commentId
     * @param int|null $parentId
     */
    public function __construct(
        public int $companyId,
        public int $newsId,
        public int $commentId,
        public ?int $parentId,
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
        return 'comment.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'news_id' => $this->newsId,
            'comment_id' => $this->commentId,
            'parent_id' => $this->parentId,
        ];
    }
}
