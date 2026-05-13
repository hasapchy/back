<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageTemplateReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $creator = data_get($this->resource, 'creator');
        $company = data_get($this->resource, 'company');

        return [
            'id' => data_get($this->resource, 'id'),
            'type' => data_get($this->resource, 'type'),
            'name' => data_get($this->resource, 'name'),
            'company_id' => data_get($this->resource, 'company_id'),
            'creator_id' => data_get($this->resource, 'creator_id'),
            'is_active' => data_get($this->resource, 'is_active'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
                'surname' => data_get($creator, 'surname'),
            ] : null,
            'company' => $company ? [
                'id' => data_get($company, 'id'),
                'name' => data_get($company, 'name'),
            ] : null,
        ];
    }
}
