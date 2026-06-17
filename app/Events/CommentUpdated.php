<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param int $companyId
     * @param int $newsId
     * @param int $commentId
     * @param string $body
     * @param int|null $parentId
     */
    public function __construct(
        public int $companyId,
        public int $newsId,
        public int $commentId,
        public string $body,
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
        return 'comment.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'news_id' => $this->newsId,
            'comment_id' => $this->commentId,
            'body' => $this->body,
            'parent_id' => $this->parentId,
        ];
    }
}
