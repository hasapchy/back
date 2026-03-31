<?php

namespace App\Http\Resources;

use App\Models\WhReceipt;

class WarehouseReceiptResource extends BaseDomainResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof WhReceipt) {
            return parent::toArray($request);
        }

        /** @var WhReceipt $receipt */
        $receipt = $this->resource;
        $data = $receipt->toArray();
        unset($data['products']);
        $data['products'] = WarehouseReceiptProductResource::collection($receipt->products)->resolve();

        return $this->normalizeCreator($data);
    }
}

