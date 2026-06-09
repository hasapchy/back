<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriveFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'folder_id' => $this->folder_id,
            'creator_id' => $this->creator_id,
            'disk' => $this->disk,
            'name' => $this->name,
            'stored_name' => $this->stored_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size' => (int) $this->size,
            'is_shared' => (bool) ($this->is_shared ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'surname' => $this->creator->surname,
            ]),
        ];
    }
}
