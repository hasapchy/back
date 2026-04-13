<?php

namespace App\Events;

use App\Broadcasting\CompanyPrivateChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionCreated implements ShouldBroadcast
{
    use Dispatchable, Queueable, SerializesModels;

    /**
     * @param  int  $companyId
     * @param  int  $transactionId
     * @param  int  $creatorId
     */
    public function __construct(
        public int $companyId,
        public int $transactionId,
        public int $creatorId,
    ) {
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('company.'.$this->companyId.'.'.CompanyPrivateChannel::SEGMENT_TRANSACTIONS),
        ];
    }

    public function broadcastAs(): string
    {
        return 'transaction.created';
    }
}
