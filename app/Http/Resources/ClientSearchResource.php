<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $client = $this->resource;
        if (!$client instanceof Client) {
            return [];
        }
        $primaryPhone = $client->phones->first();

        return [
            'id' => $client->id,
            'client_type' => $client->client_type,
            'balance' => $client->balance,
            'is_supplier' => (bool) $client->is_supplier,
            'is_conflict' => (bool) $client->is_conflict,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'position' => $client->position,
            'primary_phone' => $primaryPhone ? $primaryPhone->phone : null,
        ];
    }
}

