<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @var bool
     */
    public $preserveKeys = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['transaction_category_bindings']);

        $data['transaction_category_bindings'] = $this->resource->getTransactionCategoryBindingsAttribute();

        return $data;
    }
}
