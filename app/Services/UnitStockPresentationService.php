<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Support\Collection;

class UnitStockPresentationService
{
    public function __construct(
        private UnitConversionGraphService $graphService
    ) {
    }

    /**
     * @param  Collection<int, \App\Models\Product>  $products
     */
    public function attachStockByUnitsForProducts(Collection $products, ?int $companyId): void
    {
        if ($companyId === null || $products->isEmpty()) {
            $this->clearStockByUnits($products);

            return;
        }

        $edges = UnitConversion::query()->where('company_id', $companyId)->get();
        if ($edges->isEmpty()) {
            $this->clearStockByUnits($products);

            return;
        }

        $unitIds = $edges->pluck('parent_unit_id')->merge($edges->pluck('child_unit_id'));
        foreach ($products as $p) {
            if ($p->unit_id) {
                $unitIds->push($p->unit_id);
            }
        }
        $shortNames = Unit::query()->whereIn('id', $unitIds->unique()->all())->pluck('short_name', 'id')->all();

        foreach ($products as $product) {
            $product->stock_by_units = $this->buildForProduct($product, $edges, $shortNames);
        }
    }

    /**
     * @param  Collection<int, \App\Models\Product>  $products
     */
    private function clearStockByUnits(Collection $products): void
    {
        $products->each(static function ($p) {
            $p->stock_by_units = [];
        });
    }

    /**
     * @param  Collection<int, UnitConversion>  $edges
     * @param  array<int, string>  $shortNames
     * @return array<int, array{unit_id: int, short_name: string, quantity: string}>
     */
    private function buildForProduct(\App\Models\Product $product, Collection $edges, array $shortNames): array
    {
        $baseUnitId = $product->unit_id;
        if ($baseUnitId === null) {
            return [];
        }

        $adj = $this->graphService->buildUndirectedAdjacencyForPresentation($edges);
        if (! isset($adj[$baseUnitId]) || $adj[$baseUnitId] === []) {
            return [];
        }

        $sq = $this->normalizeDecimal((string) $product->stock_quantity);
        if (bccomp($sq, '0', 5) !== 1) {
            return [];
        }

        $kMap = [(string) $baseUnitId => '1'];
        $queue = [$baseUnitId];
        while ($queue !== []) {
            $u = array_shift($queue);
            $ku = $kMap[(string) $u];
            foreach ($adj[$u] ?? [] as $v => $meta) {
                $vk = (string) $v;
                if (isset($kMap[$vk])) {
                    continue;
                }
                $q = $meta['qty'];
                $kMap[$vk] = $meta['is_down'] ? bcmul($ku, $q, 12) : bcdiv($ku, $q, 12);
                $queue[] = $v;
            }
        }

        $out = [];
        $baseKey = (string) $baseUnitId;
        foreach ($kMap as $uid => $k) {
            if ($uid === $baseKey) {
                continue;
            }
            $qty = bcmul($sq, $k, 5);
            if (bccomp($qty, '0', 5) !== 1) {
                continue;
            }
            $unitId = (int) $uid;
            $out[] = [
                'unit_id' => $unitId,
                'short_name' => $shortNames[$unitId],
                'quantity' => $this->trimZeros($qty),
            ];
        }

        usort($out, static fn ($a, $b) => strcmp((string) $a['quantity'], (string) $b['quantity']));

        return array_slice($out, 0, 6);
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
