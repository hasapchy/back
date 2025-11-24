<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CompanyResource extends BaseResource
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
            'name' => $this->name,
            'logo' => $this->logo,
            'logo_url' => $this->assetUrl($this->logo),
            'show_deleted_transactions' => $this->toBoolean($this->show_deleted_transactions),
            'rounding_decimals' => $this->rounding_decimals,
            'rounding_enabled' => $this->toBoolean($this->rounding_enabled),
            'rounding_direction' => $this->rounding_direction,
            'rounding_custom_threshold' => $this->rounding_custom_threshold,
            'rounding_quantity_decimals' => $this->rounding_quantity_decimals,
            'rounding_quantity_enabled' => $this->toBoolean($this->rounding_quantity_enabled),
            'rounding_quantity_direction' => $this->rounding_quantity_direction,
            'rounding_quantity_custom_threshold' => $this->rounding_quantity_custom_threshold,
            'skip_project_order_balance' => $this->toBoolean($this->skip_project_order_balance),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

