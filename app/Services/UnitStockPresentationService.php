<?php

namespace App\Services;

use App\Models\ProductUnitConversion;
use App\Models\Unit;
use App\Models\WhReceiptProduct;
use Illuminate\Support\Collection;

class UnitStockPresentationService
{
    public function __construct(
        private ProductUnitToBaseFactorResolver $factorResolver
    ) {
    }

    /**
     * @param  Collection<int, \App\Models\Product>  $products
     */
    public function attachStockByUnitsForProducts(Collection $products): void
    {
        if ($products->isEmpty()) {
            return;
        }

        $productIds = $products->pluck('id')->map(static fn ($id) => (int) $id)->unique()->values()->all();
        $edgesByProduct = $this->loadProductConversionEdgesGrouped($productIds);
        $shortNames = $this->buildShortNamesForProducts($products, $edgesByProduct);

        foreach ($products as $product) {
            $pid = (int) $product->id;
            $edges = $edgesByProduct->get($pid, collect());
            $product->stock_by_units = $this->buildForProduct($product, $edges, $shortNames);
            $product->alternate_unit_options = $this->buildAlternateUnitOptions($product, $edges, $shortNames);
        }
    }

    /**
     * @param  iterable<int, object>  $items  product_id, unit_id (база товара), quantity
     */
    public function attachStockByUnitsToWarehouseStockItems(iterable $items): void
    {
        $list = is_array($items) ? $items : iterator_to_array($items);
        if ($list === []) {
            return;
        }
        $this->populateStockByUnitsOnRows($list);
    }

    /**
     * @param  iterable<int, WhReceiptProduct|\App\Models\WhPurchaseProduct>  $lines
     */
    public function attachStockByUnitsToProductLines(iterable $lines): void
    {
        $list = is_array($lines) ? $lines : iterator_to_array($lines);
        $rows = [];
        $targets = [];
        foreach ($list as $line) {
            $product = $line->product;
            if ($product === null || ! $product->unit_id) {
                $line->stock_by_units = [];

                continue;
            }
            $row = new \stdClass;
            $row->product_id = (int) $line->product_id;
            $row->unit_id = (int) $product->unit_id;
            $row->quantity = $line->quantity;
            $rows[] = $row;
            $targets[] = $line;
        }
        if ($rows === []) {
            return;
        }
        $this->populateStockByUnitsOnRows($rows);
        foreach ($rows as $i => $row) {
            $targets[$i]->stock_by_units = $row->stock_by_units;
        }
    }

    /**
     * @param  iterable<int, WhReceiptProduct>  $lines
     */
    public function attachStockByUnitsToReceiptLines(iterable $lines): void
    {
        $this->attachStockByUnitsToProductLines($lines);
    }

    /**
     * @param  array<int, object>  $list
     */
    private function populateStockByUnitsOnRows(array $list): void
    {
        $productIds = [];
        foreach ($list as $item) {
            if (! isset($item->product_id)) {
                continue;
            }
            $productIds[] = (int) $item->product_id;
        }
        $productIds = array_values(array_unique($productIds));
        $edgesByProduct = $this->loadProductConversionEdgesGrouped($productIds);

        $unitIds = collect();
        foreach ($edgesByProduct as $edges) {
            foreach ($edges as $row) {
                $unitIds->push($row->parent_unit_id, $row->child_unit_id);
            }
        }
        foreach ($list as $item) {
            if ($item->unit_id) {
                $unitIds->push((int) $item->unit_id);
            }
        }
        $shortNames = Unit::query()->whereIn('id', $unitIds->unique()->all())->pluck('short_name', 'id')->all();

        foreach ($list as $item) {
            $baseUnitId = (int) $item->unit_id;
            if ($baseUnitId === 0) {
                $item->stock_by_units = [];

                continue;
            }
            $pid = (int) $item->product_id;
            $edges = $pid > 0 ? $edgesByProduct->get($pid, collect()) : collect();
            $sq = $this->normalizeDecimal((string) ($item->quantity ?? 0));
            if (bccomp($sq, '0', 5) !== 1) {
                $item->stock_by_units = [];

                continue;
            }
            $item->stock_by_units = $this->alternateRowsForBaseQuantity($sq, $baseUnitId, $edges, $shortNames);
        }
    }

    /**
     * @param  array<int>  $productIds
     * @return Collection<int, Collection<int, ProductUnitConversion>>
     */
    private function loadProductConversionEdgesGrouped(array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return ProductUnitConversion::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy(static fn ($row) => (int) $row->product_id);
    }

    /**
     * @param  Collection<int, \App\Models\Product>  $products
     * @param  Collection<int, Collection<int, ProductUnitConversion>>  $edgesByProduct
     * @return array<int, string>
     */
    private function buildShortNamesForProducts(Collection $products, Collection $edgesByProduct): array
    {
        $unitIds = collect();
        foreach ($edgesByProduct as $edges) {
            foreach ($edges as $row) {
                $unitIds->push($row->parent_unit_id, $row->child_unit_id);
            }
        }
        foreach ($products as $p) {
            if ($p->unit_id) {
                $unitIds->push($p->unit_id);
            }
        }

        return Unit::query()->whereIn('id', $unitIds->unique()->all())->pluck('short_name', 'id')->all();
    }

    /**
     * @param  Collection<int, ProductUnitConversion>  $edges
     * @param  array<int, string>  $shortNames
     * @return array<int, array{unit_id: int, short_name: string, quantity: string, to_base_factor: string}>
     */
    private function alternateRowsForBaseQuantity(string $sq, int $baseUnitId, Collection $edges, array $shortNames): array
    {
        $baseUnitsPerOne = $this->factorResolver->baseUnitsPerOneMap($edges, $baseUnitId);
        if (count($baseUnitsPerOne) <= 1) {
            return [];
        }

        $out = [];
        $baseKey = (string) $baseUnitId;
        foreach ($baseUnitsPerOne as $uid => $tb) {
            if ($uid === $baseKey) {
                continue;
            }
            $qty = $this->trimZeros(bcdiv($sq, $tb, 15));
            if (bccomp($qty, '0', 10) !== 1) {
                continue;
            }
            $unitId = (int) $uid;
            $out[] = [
                'unit_id' => $unitId,
                'short_name' => $shortNames[$unitId] ?? '',
                'quantity' => $qty,
                'to_base_factor' => $this->trimZeros($tb),
            ];
        }

        $byUnit = [];
        foreach ($out as $row) {
            $byUnit[$row['unit_id']] = $row;
        }
        $out = array_values($byUnit);

        usort($out, static fn ($a, $b) => strcmp((string) $a['quantity'], (string) $b['quantity']));

        return array_slice($out, 0, 6);
    }

    /**
     * @param  Collection<int, ProductUnitConversion>  $edges
     * @param  array<int, string>  $shortNames
     * @return array<int, array{unit_id: int, short_name: string, quantity: string, to_base_factor: string}>
     */
    private function buildForProduct(\App\Models\Product $product, Collection $edges, array $shortNames): array
    {
        $baseUnitId = $product->unit_id;
        if ($baseUnitId === null) {
            return [];
        }

        $sq = $this->normalizeDecimal((string) $product->stock_quantity);
        if (bccomp($sq, '0', 5) !== 1) {
            return [];
        }

        return $this->alternateRowsForBaseQuantity($sq, (int) $baseUnitId, $edges, $shortNames);
    }

    /**
     * @param  Collection<int, ProductUnitConversion>  $edges
     * @param  array<int, string>  $shortNames
     * @return array<int, array{unit_id: int, short_name: string, quantity: string, to_base_factor: string}>
     */
    private function buildAlternateUnitOptions(\App\Models\Product $product, Collection $edges, array $shortNames): array
    {
        $baseUnitId = $product->unit_id;
        if ($baseUnitId === null || (int) $baseUnitId === 0) {
            return [];
        }

        return $this->alternateRowsForBaseQuantity('1', (int) $baseUnitId, $edges, $shortNames);
    }

    private function normalizeDecimal(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '0' : $this->trimZeros($value);
    }

    private function trimZeros(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
