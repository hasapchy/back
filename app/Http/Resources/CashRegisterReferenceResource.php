<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $currency = data_get($this->resource, 'currency');
        $users = $this->resource->users ?? [];

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'balance' => data_get($this->resource, 'balance'),
            'is_cash' => data_get($this->resource, 'is_cash'),
            'is_working_minus' => data_get($this->resource, 'is_working_minus'),
            'currency_id' => data_get($this->resource, 'currency_id'),
            'currency' => $currency ? [
                'id' => data_get($currency, 'id'),
                'name' => data_get($currency, 'name'),
                'symbol' => data_get($currency, 'symbol'),
            ] : null,
            'icon' => data_get($this->resource, 'icon'),
            'users' => collect($users)->map(static function ($user) {
                return [
                    'id' => data_get($user, 'id'),
                    'name' => data_get($user, 'name'),
                ];
            })->values()->all(),
        ];
    }
}
