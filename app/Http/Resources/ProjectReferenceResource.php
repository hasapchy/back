<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;
class ProjectReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $client = data_get($this->resource, 'client');
        $currency = data_get($this->resource, 'currency');
        $status = data_get($this->resource, 'status');
        $creator = data_get($this->resource, 'creator') ?? data_get($this->resource, 'user');
        $users = $this->resource->users ?? [];

        return [
            'budget' => data_get($this->resource, 'budget'),
            'client' => $client ? $this->referenceClientPayload($client) : null,
            'client_id' => data_get($this->resource, 'client_id'),
            'created_at' => $this->formatDateTimeValue(data_get($this->resource, 'created_at')),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
                'surname' => data_get($creator, 'surname'),
                'photo' => data_get($creator, 'photo'),
            ] : null,
            'creator_id' => data_get($this->resource, 'creator_id'),
            'currency' => $currency ? [
                'id' => data_get($currency, 'id'),
                'name' => data_get($currency, 'name'),
                'code' => data_get($currency, 'code'),
                'is_default' => data_get($currency, 'is_default'),
                'is_report' => data_get($currency, 'is_report'),
                'status' => data_get($currency, 'status'),
            ] : null,
            'currency_id' => data_get($this->resource, 'currency_id'),
            'date' => $this->formatDateTimeValue(data_get($this->resource, 'date')),
            'description' => data_get($this->resource, 'description'),
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'status' => $status ? [
                'id' => data_get($status, 'id'),
                'name' => data_get($status, 'name'),
                'color' => data_get($status, 'color'),
                'is_visible' => data_get($status, 'is_visible'),
            ] : null,
            'status_id' => data_get($this->resource, 'status_id'),
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
     * @param  mixed  $client
     * @return array<string, mixed>
     */
    private function referenceClientPayload($client): array
    {
        return [
            'id' => data_get($client, 'id'),
            'client_type' => data_get($client, 'client_type'),
            'first_name' => data_get($client, 'first_name'),
            'last_name' => data_get($client, 'last_name'),
            'patronymic' => data_get($client, 'patronymic'),
            'balance' => data_get($client, 'balance'),
            'is_supplier' => data_get($client, 'is_supplier'),
            'is_conflict' => data_get($client, 'is_conflict'),
            'position' => data_get($client, 'position'),
            'emails' => [],
            'phones' => [],
            'created_at' => $this->formatDateTimeValue(data_get($client, 'created_at')),
            'updated_at' => $this->formatDateTimeValue(data_get($client, 'updated_at')),
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function formatDateTimeValue($value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value !== null ? (string) $value : null;
    }
}
