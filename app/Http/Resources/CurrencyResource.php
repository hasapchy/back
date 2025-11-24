<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CurrencyResource extends BaseResource
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
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'exchange_rate' => $this->formatCurrency($this->exchange_rate, 4),
            'is_default' => $this->toBoolean($this->is_default),
            'status' => $this->status,
            'is_report' => $this->toBoolean($this->is_report),
        ];
    }
}

