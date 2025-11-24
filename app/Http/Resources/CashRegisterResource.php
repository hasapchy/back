<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CashRegisterResource extends BaseResource
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
            'name' => $this->name,
            'balance' => $this->formatCurrency($this->balance),
            'currency_id' => $this->currency_id,
            'company_id' => $this->company_id,
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

