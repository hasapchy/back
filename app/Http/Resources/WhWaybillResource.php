<?php

namespace App\Http\Resources;

use App\Models\WhWaybill;
use App\Models\WhWaybillProduct;

class WhWaybillResource extends BaseDomainResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof WhWaybill) {
            return parent::toArray($request);
        }

        /** @var WhWaybill $waybill */
        $waybill = $this->resource;
        $data = $waybill->toArray();
        unset($data['lines']);
        $data['lines'] = $waybill->lines->map(static function (WhWaybillProduct $line) {
            $row = $line->only(['id', 'waybill_id', 'product_id', 'quantity', 'price']);
            if ($line->relationLoaded('product')) {
                $row['product'] = $line->product;
            }

            return $row;
        })->values()->all();

        return $this->normalizeCreator($data);
    }
}
