<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class BaseDomainResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = is_array($this->resource)
            ? $this->resource
            : ($this->resource instanceof Model ? $this->resource->toArray() : (array) $this->resource);

        return $this->normalizeCreator($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normalizeCreator(array $data): array
    {
        if (!array_key_exists('creator', $data)) {
            if (isset($data['user']) && is_array($data['user'])) {
                $data['creator'] = $data['user'];
            } elseif (array_key_exists('creator_id', $data) || array_key_exists('creator_name', $data) || array_key_exists('creator_photo', $data)) {
                $creator = [
                    'id' => $data['creator_id'] ?? null,
                    'name' => $data['creator_name'] ?? null,
                    'photo' => $data['creator_photo'] ?? null,
                ];
                $data['creator'] = $creator['id'] === null && $creator['name'] === null && $creator['photo'] === null ? null : $creator;
            }
        }

        if (!array_key_exists('creator_id', $data) && isset($data['creator']) && is_array($data['creator'])) {
            $data['creator_id'] = $data['creator']['id'] ?? null;
        }

        return $data;
    }
}
