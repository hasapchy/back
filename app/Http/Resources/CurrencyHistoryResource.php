<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CurrencyHistoryResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'currency_id' => $this->currency_id,
            'company_id' => $this->company_id,
            'exchange_rate' => $this->formatCurrency($this->exchange_rate, 4),
            'start_date' => $this->formatDate($this->start_date),
            'end_date' => $this->formatDate($this->end_date),
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
            'company' => new CompanyResource($this->whenLoaded('company')),
        ];
    }
}

