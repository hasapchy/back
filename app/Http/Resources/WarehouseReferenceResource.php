<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $users = $this->resource->users ?? [];

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
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
