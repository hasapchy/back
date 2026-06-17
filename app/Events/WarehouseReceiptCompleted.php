<?php

namespace App\Events;

use App\Models\WhReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarehouseReceiptCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WhReceipt $receipt,
    ) {}
}
