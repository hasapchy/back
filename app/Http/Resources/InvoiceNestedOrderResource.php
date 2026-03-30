<?php

namespace App\Http\Resources;

class InvoiceNestedOrderResource extends OrderResource
{
    protected function shouldEmbedClient(): bool
    {
        return false;
    }
}
