<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnitConversion;
use Illuminate\Support\Collection;

class WarehouseLineOrigValidationService
{
    public function __construct(
        private ProductUnitToBaseFactorResolver $factorResolver,
        private RoundingService $rounding
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, string> line index => error message
     */
    public function validateLines(array $lines, ?int $companyId): array
    {
        $errors = [];
        $productIds = [];
        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pid = $row['product_id'] ?? null;
            if ($pid !== null && $pid !== '') {
                $productIds[] = (int) $pid;
            }
        }
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return [];
        }

        $productsById = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'unit_id'])
            ->keyBy('id');

        $edgesByProduct = ProductUnitConversion::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy(static fn ($row) => (int) $row->product_id);

        foreach ($lines as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $message = $this->validateLine($row, $productsById, $edgesByProduct, $companyId);
            if ($message !== null) {
                $errors[$idx] = $message;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Product>  $productsById
     * @param  Collection<int, Collection<int, ProductUnitConversion>>  $edgesByProduct
     */
    public function validateLine(
        array $row,
        Collection $productsById,
        Collection $edgesByProduct,
        ?int $companyId
    ): ?string {
        $hasUnit = isset($row['orig_unit_id']) && $row['orig_unit_id'] !== null && $row['orig_unit_id'] !== '';
        $hasQty = array_key_exists('orig_quantity', $row) && $row['orig_quantity'] !== null && $row['orig_quantity'] !== '';
        if (! $hasUnit && ! $hasQty) {
            return null;
        }
        if (! $hasUnit || ! $hasQty) {
            return (string) __('warehouse_line.orig_unit_with_orig_quantity_both_required');
        }

        $productId = (int) ($row['product_id'] ?? 0);
        $product = $productsById->get($productId);
        if ($product === null || $product->unit_id === null) {
            return (string) __('warehouse_line.orig_unit_not_allowed_for_product');
        }

        $baseUnitId = (int) $product->unit_id;
        $origUnitId = (int) $row['orig_unit_id'];
        if ($origUnitId === $baseUnitId) {
            return (string) __('warehouse_line.orig_unit_same_as_base');
        }

        $origQty = (float) $row['orig_quantity'];
        if ($origQty <= 0) {
            return (string) __('warehouse_line.orig_quantity_inconsistent_with_base');
        }

        $edges = $edgesByProduct->get($productId, collect());
        $factor = $this->factorResolver->factorAlternateToBase($edges, $baseUnitId, $origUnitId);
        if ($factor === null) {
            return (string) __('warehouse_line.orig_unit_not_allowed_for_product');
        }

        $expectedBase = $this->rounding->roundQuantityForCompany(
            $companyId,
            (float) bcmul($this->normalizeDecimal((string) $origQty), $factor, 15)
        );
        $actualBase = $this->rounding->roundQuantityForCompany($companyId, (float) ($row['quantity'] ?? 0));
        if (! $this->roundedQuantitiesEqual($expectedBase, $actualBase)) {
            return (string) __('warehouse_line.orig_quantity_inconsistent_with_base');
        }

        return null;
    }

    private function roundedQuantitiesEqual(float $expected, float $actual): bool
    {
        return bccomp(
            $this->normalizeDecimal((string) $expected),
            $this->normalizeDecimal((string) $actual),
            10
        ) === 0;
    }

    private function normalizeDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '' || ! is_numeric($value)) {
            return '0';
        }
        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' ? '0' : $value;
    }
}
