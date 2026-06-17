<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'financial_account_id' => $this->financial_account_id,
            'account_code' => $this->financialAccount?->code,
            'account_name' => $this->financialAccount?->name,
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'line_order' => $this->line_order,
            'meta' => $this->meta,
        ];
    }
}
