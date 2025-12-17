<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $primaryPhone = $this->phones->first();

        return [
            'id' => $this->id,
            'client_type' => $this->client_type,
            'balance' => $this->balance,
            'is_supplier' => (bool)$this->is_supplier,
            'is_conflict' => (bool)$this->is_conflict,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'contact_person' => $this->contact_person,
            'position' => $this->position,
            'primary_phone' => $primaryPhone ? $primaryPhone->phone : null,
        ];
    }
}

