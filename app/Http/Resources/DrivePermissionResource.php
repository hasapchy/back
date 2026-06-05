<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DrivePermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'ability' => $this->ability,
            'effect' => $this->effect,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
