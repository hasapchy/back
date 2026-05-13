<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\UnitConversion
 */
class UnitConversionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'parent_unit_id' => $this->parent_unit_id,
            'child_unit_id' => $this->child_unit_id,
            'quantity' => (string) $this->quantity,
            'parent_short_name' => $this->parentUnit->short_name,
            'child_short_name' => $this->childUnit->short_name,
        ];
    }
}
