<?php

namespace App\Http\Resources;

use App\Models\ClientBalance;
use App\Support\ClientBalancePayload;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if ($this->resource instanceof ClientBalance) {
            return ClientBalancePayload::fromModel($this->resource);
        }

        return (array) $this->resource;
    }
}
