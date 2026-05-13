<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeadStatusResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'creator_id' => $this->creator_id,
            'name' => $this->name,
            'color' => $this->color,
            'is_active' => (bool) $this->is_active,
            'sort' => (int) $this->sort,
            'kanban_outcome' => $this->kanban_outcome,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
