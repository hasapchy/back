<?php

namespace App\Http\Resources;

use App\Support\ResolvedCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $companyId = ResolvedCompany::fromRequest($request);
        $history = $this->resource->getCurrentExchangeRateForCompany($companyId);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'is_default' => $this->is_default,
            'is_report' => $this->is_report,
            'status' => $this->status,
            'company_id' => $this->company_id,
            'is_global' => $this->company_id === null,
            'current_exchange_rate' => $history ? (float) $history->exchange_rate : null,
            'rate_start_date' => $history?->start_date?->format('Y-m-d'),
        ];
    }
}
