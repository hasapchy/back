<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriveListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $payload = $this->resource;

        return [
            'parent' => $payload['parent']
                ? DriveFolderResource::make($payload['parent'])->resolve()
                : null,
            'folders' => DriveFolderResource::collection($payload['folders'])->resolve(),
            'files' => DriveFileResource::collection($payload['files'])->resolve(),
            'breadcrumbs' => $payload['breadcrumbs'],
        ];
    }
}
