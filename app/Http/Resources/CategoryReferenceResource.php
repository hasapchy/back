<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $users = $this->resource->users ?? [];
        $creator = data_get($this->resource, 'creator');

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'parent_id' => data_get($this->resource, 'parent_id'),
            'parent_name' => data_get($this->resource, 'parent_name'),
            'creator_id' => data_get($this->resource, 'creator_id'),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
            ] : null,
            'users' => collect($users)->map(static function ($user) {
                return [
                    'id' => data_get($user, 'id'),
                    'name' => data_get($user, 'name'),
                    'surname' => data_get($user, 'surname'),
                ];
            })->values()->all(),
        ];
    }
}
