<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class OrderFirstStageCountUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels, Queueable;

    public function __construct(public int $companyId)
    {
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("company.{$this->companyId}.orders"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.first-stage-count.updated';
    }
}
