<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TimelineItemCreated implements ShouldBroadcast
{
    use Dispatchable, Queueable, SerializesModels;

    /**
     * @param int $companyId
     * @param string $apiType
     * @param int $entityId
     * @param array<string, mixed> $item
     */
    public function __construct(
        public int $companyId,
        public string $apiType,
        public int $entityId,
        public array $item,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->companyId}.timeline.{$this->apiType}.{$this->entityId}"),
        ];
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'timeline.item.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'api_type' => $this->apiType,
            'entity_id' => $this->entityId,
            'item' => $this->item,
        ];
    }
}
