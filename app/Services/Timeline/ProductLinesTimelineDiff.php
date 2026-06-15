<?php

namespace App\Services\Timeline;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ProductLinesTimelineDiff
{
    /**
     * @param  Collection<int, Model>  $existingLines
     * @param  array<int, array<string, mixed>>  $incomingLines
     * @param  callable|null  $lineHasChanges
     * @return array{added: list<string>, removed: list<string>, updated: list<string>}
     */
    public function buildSummary(Collection $existingLines, array $incomingLines, ?callable $lineHasChanges = null): array
    {
        $compare = $lineHasChanges ?? [self::class, 'pricedLineHasChanges'];
        $existingByProductId = $existingLines->keyBy('product_id');
        $incomingByProductId = collect($incomingLines)->keyBy(fn (array $line) => (int) ($line['product_id'] ?? 0));

        $summary = ['added' => [], 'removed' => [], 'updated' => []];
        $productIds = $existingByProductId->keys()
            ->merge($incomingByProductId->keys())
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        $labels = $this->resolveProductLabels($productIds);

        foreach ($incomingByProductId as $productId => $incoming) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }

            $existing = $existingByProductId->get($productId);
            $label = $labels[$productId] ?? (string) $productId;

            if ($existing === null) {
                $summary['added'][] = $label;

                continue;
            }

            if ($compare($existing, $incoming)) {
                $summary['updated'][] = $label;
            }
        }

        foreach ($existingByProductId as $productId => $existing) {
            $productId = (int) $productId;
            if ($productId <= 0 || $incomingByProductId->has($productId)) {
                continue;
            }

            $summary['removed'][] = $labels[$productId] ?? (string) $productId;
        }

        return $summary;
    }

    /**
     * @param  Model  $existing
     * @param  array<string, mixed>  $incoming
     */
    public static function pricedLineHasChanges(Model $existing, array $incoming): bool
    {
        if (self::quantityLineHasChanges($existing, $incoming)) {
            return true;
        }

        if (abs((float) ($existing->price ?? 0) - (float) ($incoming['price'] ?? 0)) > 0.0001) {
            return true;
        }

        $incomingOrigPrice = $incoming['orig_unit_price'] ?? $incoming['price'] ?? 0;

        return abs((float) ($existing->orig_unit_price ?? 0) - (float) $incomingOrigPrice) > 0.0001
            || (int) ($existing->orig_currency_id ?? 0) !== (int) ($incoming['orig_currency_id'] ?? 0)
            || (int) ($existing->orig_unit_id ?? 0) !== (int) ($incoming['orig_unit_id'] ?? 0)
            || abs((float) ($existing->orig_quantity ?? 0) - (float) ($incoming['orig_quantity'] ?? 0)) > 0.0001;
    }

    /**
     * @param  Model  $existing
     * @param  array<string, mixed>  $incoming
     */
    public static function writeoffLineHasChanges(Model $existing, array $incoming): bool
    {
        if (self::pricedLineHasChanges($existing, $incoming)) {
            return true;
        }

        return (int) ($existing->source_receipt_product_id ?? 0) !== (int) ($incoming['source_receipt_product_id'] ?? 0);
    }

    /**
     * @param  Model  $existing
     * @param  array<string, mixed>  $incoming
     */
    public static function movementLineHasChanges(Model $existing, array $incoming): bool
    {
        return self::quantityLineHasChanges($existing, $incoming)
            || (int) ($existing->orig_unit_id ?? 0) !== (int) ($incoming['orig_unit_id'] ?? 0)
            || abs((float) ($existing->orig_quantity ?? 0) - (float) ($incoming['orig_quantity'] ?? 0)) > 0.0001;
    }

    /**
     * @param  Model  $existing
     * @param  array<string, mixed>  $incoming
     */
    private static function quantityLineHasChanges(Model $existing, array $incoming): bool
    {
        return abs((float) ($existing->quantity ?? 0) - (float) ($incoming['quantity'] ?? 0)) > 0.0001;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    private function resolveProductLabels(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
