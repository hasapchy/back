<?php

namespace App\Http\Resources;

use App\Models\Sale;

class SaleResource extends BaseDomainResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof Sale) {
            return parent::toArray($request);
        }

        /** @var Sale $sale */
        $sale = $this->resource;
        $data = $sale->toArray();
        unset($data['products']);
        $data['products'] = SaleProductResource::collection($sale->products)->resolve();

        return $this->normalizeCreator($data);
    }
}
