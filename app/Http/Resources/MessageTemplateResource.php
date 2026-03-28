<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageTemplateResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $creator = data_get($this->resource, 'creator');

        if (is_array($this->resource)) {
            return [
                'id' => data_get($this->resource, 'id'),
                'type' => data_get($this->resource, 'type'),
                'name' => data_get($this->resource, 'name'),
                'content' => data_get($this->resource, 'content'),
                'company_id' => data_get($this->resource, 'company_id'),
                'company' => data_get($this->resource, 'company'),
                'creator_id' => data_get($this->resource, 'creator_id'),
                'creator' => $creator ? [
                    'id' => data_get($creator, 'id'),
                    'name' => data_get($creator, 'name'),
                    'surname' => data_get($creator, 'surname'),
                    'email' => data_get($creator, 'email'),
                    'photo' => data_get($creator, 'photo'),
                ] : null,
                'is_active' => data_get($this->resource, 'is_active'),
                'created_at' => data_get($this->resource, 'created_at'),
                'updated_at' => data_get($this->resource, 'updated_at'),
            ];
        }

        if ($this->resource instanceof Model) {
            return [
                'id' => $this->resource->id,
                'type' => $this->resource->type,
                'name' => $this->resource->name,
                'content' => $this->resource->content,
                'company_id' => $this->resource->company_id,
                'company' => $this->whenLoaded('company'),
                'creator_id' => $this->resource->creator_id,
                'creator' => $this->whenLoaded('creator'),
                'is_active' => $this->resource->is_active,
                'created_at' => $this->resource->created_at,
                'updated_at' => $this->resource->updated_at,
            ];
        }

        return [
            'id' => data_get($this->resource, 'id'),
            'type' => data_get($this->resource, 'type'),
            'name' => data_get($this->resource, 'name'),
            'content' => data_get($this->resource, 'content'),
            'company_id' => data_get($this->resource, 'company_id'),
            'company' => data_get($this->resource, 'company'),
            'creator_id' => data_get($this->resource, 'creator_id'),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
                'surname' => data_get($creator, 'surname'),
                'email' => data_get($creator, 'email'),
                'photo' => data_get($creator, 'photo'),
            ] : null,
            'is_active' => data_get($this->resource, 'is_active'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
