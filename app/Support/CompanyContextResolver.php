<?php

namespace App\Support;

use App\Exceptions\CompanyContextMissingException;
use App\Models\Warehouse;

class CompanyContextResolver
{
    /**
     * @param  Warehouse|null  $warehouse
     * @param  string  $context
     * @return int
     */
    public static function requireWarehouseCompanyId(?Warehouse $warehouse, string $context): int
    {
        $companyId = (int) ($warehouse?->company_id ?? 0);
        if ($companyId <= 0) {
            throw new CompanyContextMissingException("Company context missing for {$context}.");
        }

        return $companyId;
    }
}
