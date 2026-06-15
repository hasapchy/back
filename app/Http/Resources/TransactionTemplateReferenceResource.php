<?php

namespace App\Http\Resources;

use App\Support\ClientReferencePayload;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class TransactionTemplateReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $cashRegister = data_get($this->resource, 'cash_register') ?? data_get($this->resource, 'cashRegister');
        $currency = data_get($this->resource, 'currency');
        $category = data_get($this->resource, 'category');
        $project = data_get($this->resource, 'project');
        $client = data_get($this->resource, 'client');
        $creator = data_get($this->resource, 'creator');

        return [
            'amount' => data_get($this->resource, 'amount'),
            'cash_id' => data_get($this->resource, 'cash_id'),
            'cash_register' => $cashRegister ? [
                'id' => data_get($cashRegister, 'id'),
                'name' => data_get($cashRegister, 'name'),
                'currency_id' => data_get($cashRegister, 'currency_id'),
                'company_id' => data_get($cashRegister, 'company_id'),
                'is_cash' => data_get($cashRegister, 'is_cash'),
                'color' => data_get($cashRegister, 'color'),
            ] : null,
            'category' => $category ? [
                'id' => data_get($category, 'id'),
                'name' => data_get($category, 'name'),
                'type' => data_get($category, 'type'),
            ] : null,
            'category_id' => data_get($this->resource, 'category_id'),
            'client' => $client ? $this->referenceClientPayload($client) : null,
            'client_id' => data_get($this->resource, 'client_id'),
            'created_at' => $this->formatDateTimeValue(data_get($this->resource, 'created_at')),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
                'surname' => data_get($creator, 'surname'),
            ] : null,
            'creator_id' => data_get($this->resource, 'creator_id'),
            'currency' => $currency ? [
                'id' => data_get($currency, 'id'),
                'name' => data_get($currency, 'name'),
                'code' => data_get($currency, 'code'),
            ] : null,
            'currency_id' => data_get($this->resource, 'currency_id'),
            'icon' => data_get($this->resource, 'icon'),
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'note' => data_get($this->resource, 'note'),
            'project' => $project ? [
                'id' => data_get($project, 'id'),
                'name' => data_get($project, 'name'),
            ] : null,
            'project_id' => data_get($this->resource, 'project_id'),
            'type' => data_get($this->resource, 'type'),
            'updated_at' => $this->formatDateTimeValue(data_get($this->resource, 'updated_at')),
        ];
    }

    /**
     * @param  mixed  $client
     * @return array<string, mixed>
     */
    private function referenceClientPayload($client): array
    {
        return array_merge(ClientReferencePayload::build($client), [
            'emails' => $this->normalizeListPayload(data_get($client, 'emails')),
            'phones' => $this->normalizeListPayload(data_get($client, 'phones')),
        ]);
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

    /**
     * @param  mixed  $value
     * @return array<int, mixed>
     */
    private function normalizeListPayload($value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof Collection) {
            return $value->values()->all();
        }

        return is_array($value) ? array_values($value) : [];
    }
}
