<?php

namespace App\Http\Resources;

use App\Models\ProjectContract;

class ProjectContractResource extends BaseDomainResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        if ($this->resource instanceof ProjectContract) {
            $this->resource->loadMissing('project:id,client_id');
            if (($data['client_id'] ?? null) === null && $this->resource->project) {
                $data['client_id'] = $this->resource->project->client_id;
            }
        }

        return $data;
    }
}
