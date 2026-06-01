<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
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
        $creator = data_get($this->resource, 'creator');
        $users = $this->resource->users ?? [];

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'balance' => data_get($this->resource, 'balance'),
            'is_cash' => data_get($this->resource, 'is_cash'),
            'is_working_minus' => data_get($this->resource, 'is_working_minus'),
            'currency_id' => data_get($this->resource, 'currency_id'),
            'creator_id' => data_get($this->resource, 'creator_id'),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
            ] : null,
            'currency' => $currency ? [
                'id' => data_get($currency, 'id'),
                'name' => data_get($currency, 'name'),
                'code' => data_get($currency, 'code'),
            ] : null,
            'icon' => data_get($this->resource, 'icon'),
            'color' => data_get($this->resource, 'color'),
            'created_at' => $this->formatDateTimeValue(data_get($this->resource, 'created_at')),
            'updated_at' => $this->formatDateTimeValue(data_get($this->resource, 'updated_at')),
            'users' => collect($users)->map(static function ($user) {
                return [
                    'id' => data_get($user, 'id'),
                    'name' => data_get($user, 'name'),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function formatDateTimeValue($value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value !== null ? (string) $value : null;
    }
}
