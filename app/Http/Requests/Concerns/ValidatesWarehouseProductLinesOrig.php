<?php

namespace App\Http\Requests\Concerns;

use App\Services\WarehouseLineOrigValidationService;
use App\Support\ResolvedCompany;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesWarehouseProductLinesOrig
{
    /**
     * @return array<string, mixed>
     */
    protected function warehouseProductLinesOrigRules(string $prefix = 'products'): array
    {
        return [
            "{$prefix}.*.orig_unit_id" => ['nullable', 'integer', 'exists:units,id'],
            "{$prefix}.*.orig_quantity" => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function addWarehouseProductLinesOrigPairValidator(Validator $validator, string $prefix = 'products'): void
    {
        $validator->after(function (Validator $v) use ($prefix): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $products = $this->input($prefix);
            if (! is_array($products)) {
                return;
            }
            foreach ($products as $idx => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $hasUnit = isset($row['orig_unit_id']) && $row['orig_unit_id'] !== null && $row['orig_unit_id'] !== '';
                $hasQty = array_key_exists('orig_quantity', $row) && $row['orig_quantity'] !== null && $row['orig_quantity'] !== '';
                if ($hasUnit xor $hasQty) {
                    $v->errors()->add(
                        "{$prefix}.{$idx}.orig_unit_id",
                        (string) __('warehouse_line.orig_unit_with_orig_quantity_both_required')
                    );
                }
            }
        });
    }

    protected function addWarehouseProductLinesOrigConsistencyValidator(Validator $validator, string $prefix = 'products'): void
    {
        $validator->after(function (Validator $v) use ($prefix): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $products = $this->input($prefix);
            if (! is_array($products)) {
                return;
            }
            $companyId = ResolvedCompany::fromRequest($this);
            $errors = app(WarehouseLineOrigValidationService::class)->validateLines($products, $companyId);
            foreach ($errors as $idx => $message) {
                $v->errors()->add("{$prefix}.{$idx}.orig_unit_id", $message);
            }
        });
    }
}
