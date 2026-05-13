<?php

namespace App\Http\Requests\Concerns;

use App\Models\Category;
use App\Models\Warehouse;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesSimpleUserCategoryAndWarehouseCompanies
{
    /**
     * @param  array<int, int>  $companyIds
     */
    protected function validateCategoryAndWarehouseBelongToCompanyIds(
        Validator $v,
        array $companyIds,
        int $categoryId,
        int $warehouseId,
        string $categoryError,
        string $warehouseError
    ): void {
        if ($categoryId > 0) {
            $catCompany = Category::whereKey($categoryId)->value('company_id');
            if ($catCompany === null || ! in_array((int) $catCompany, $companyIds, true)) {
                $v->errors()->add('simple_category_id', $categoryError);
            }
        }
        if ($warehouseId > 0) {
            $whCompany = Warehouse::whereKey($warehouseId)->value('company_id');
            if ($whCompany === null || ! in_array((int) $whCompany, $companyIds, true)) {
                $v->errors()->add('simple_warehouse_id', $warehouseError);
            }
        }
    }
}
