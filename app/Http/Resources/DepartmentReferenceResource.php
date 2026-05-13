<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $users = $this->resource->users ?? [];
        $head = data_get($this->resource, 'head');
        $deputyHead = data_get($this->resource, 'deputyHead');

        return [
            'id' => data_get($this->resource, 'id'),
            'title' => data_get($this->resource, 'title'),
            'description' => data_get($this->resource, 'description'),
            'parent_id' => data_get($this->resource, 'parent_id'),
            'head_id' => data_get($this->resource, 'head_id'),
            'deputy_head_id' => data_get($this->resource, 'deputy_head_id'),
            'company_id' => data_get($this->resource, 'company_id'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
            'users' => collect($users)->map(static function ($user) {
                return [
                    'id' => data_get($user, 'id'),
                    'name' => data_get($user, 'name'),
                    'surname' => data_get($user, 'surname'),
                ];
            })->values()->all(),
            'head' => $head ? [
                'id' => data_get($head, 'id'),
                'name' => data_get($head, 'name'),
                'surname' => data_get($head, 'surname'),
            ] : null,
            'deputy_head' => $deputyHead ? [
                'id' => data_get($deputyHead, 'id'),
                'name' => data_get($deputyHead, 'name'),
                'surname' => data_get($deputyHead, 'surname'),
            ] : null,
        ];
    }
}
