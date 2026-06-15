<?php

namespace App\Http\Resources;

use App\Support\ClientBalanceViewAccess;
use App\Support\ResolvedCompany;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $primaryPhone = $this->phones->first();

        $user = auth('api')->user();
        $companyId = ResolvedCompany::fromRequest($request);

        return [
            'id' => $this->id,
            'client_type' => $this->client_type,
            'balance' => ClientBalanceViewAccess::visibleDefaultBalanceValue($this->resource, $user, $companyId),
            'is_supplier' => (bool)$this->is_supplier,
            'is_conflict' => (bool)$this->is_conflict,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'position' => $this->position,
            'primary_phone' => $primaryPhone ? $primaryPhone->phone : null,
        ];
    }
}

