<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TaskStatusReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $creator = data_get($this->resource, 'creator');

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'color' => data_get($this->resource, 'color'),
            'kanban_outcome' => data_get($this->resource, 'kanban_outcome'),
            'creator_id' => data_get($this->resource, 'creator_id'),
            'user' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
                'surname' => data_get($creator, 'surname'),
            ] : null,
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
