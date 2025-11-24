<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CompanyRoundingRuleResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'context' => $this->context,
            'decimals' => $this->decimals,
            'direction' => $this->direction,
            'custom_threshold' => $this->formatCurrency($this->custom_threshold),
            'company' => new CompanyResource($this->whenLoaded('company')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

