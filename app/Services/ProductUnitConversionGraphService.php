<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ProductUnitConversionGraphService
{
    public function __construct(
        private UnitConversionGraphService $packQuantity
    ) {
    }

    /**
     * @param  list<array{parent_unit_id: int, child_unit_id: int, quantity: mixed}>  $rows
     *
     * @throws ValidationException
     */
    public function assertReplacementSetValid(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $pk = (int) $row['parent_unit_id'].':'.(int) $row['child_unit_id'];
            if (isset($seen[$pk])) {
                throw ValidationException::withMessages([
                    'product_unit_conversions' => [__('units.conversion_conflicting_paths')],
                ]);
            }
            $seen[$pk] = true;
        }

        for ($i = 0, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            $parentId = (int) $row['parent_unit_id'];
            $childId = (int) $row['child_unit_id'];
            $qty = $this->normalizeDecimal((string) $row['quantity']);
            $partial = array_slice($rows, 0, $i);

            if ($parentId === $childId) {
                throw ValidationException::withMessages([
                    'product_unit_conversions' => [__('units.conversion_parent_equals_child')],
                ]);
            }
            if (bccomp($qty, '0', 8) !== 1) {
                throw ValidationException::withMessages([
                    'product_unit_conversions' => [__('units.conversion_quantity_positive')],
                ]);
            }

            foreach ($partial as $r) {
                if ((int) $r['parent_unit_id'] === $childId && (int) $r['child_unit_id'] === $parentId) {
                    throw ValidationException::withMessages([
                        'product_unit_conversions' => [__('units.conversion_reverse_exists')],
                    ]);
                }
            }

            $adjWithoutNew = $this->buildAdjacencyFromRows($partial);

            if ($this->existsDirectedPath($childId, $parentId, $adjWithoutNew)) {
                throw ValidationException::withMessages([
                    'product_unit_conversions' => [__('units.conversion_cycle')],
                ]);
            }

            $factors = $this->directedPathProductsFrom($parentId, $adjWithoutNew);
            if (isset($factors[(string) $childId]) && bccomp($factors[(string) $childId], $qty, 8) !== 0) {
                throw ValidationException::withMessages([
                    'product_unit_conversions' => [__('units.conversion_path_quantity_mismatch')],
                ]);
            }
        }

        if ($rows === []) {
            return;
        }

        $adjFull = $this->buildAdjacencyFromRows($rows);
        $started = [];
        foreach ($rows as $row) {
            $p = (int) $row['parent_unit_id'];
            if (isset($started[$p])) {
                continue;
            }
            $this->directedPathProductsFrom($p, $adjFull);
            $started[$p] = true;
        }
    }

    /**
     * @param  list<array{parent_unit_id: int, child_unit_id: int, quantity: mixed}>  $rows
     * @return array<int, list<array{to: int, qty: string}>>
     */
    private function buildAdjacencyFromRows(array $rows): array
    {
        $adj = [];
        foreach ($rows as $row) {
            $p = (int) $row['parent_unit_id'];
            $c = (int) $row['child_unit_id'];
            $edgeQty = $this->normalizeDecimal((string) $row['quantity']);
            $adj[$p] ??= [];
            $adj[$p][] = ['to' => $c, 'qty' => $edgeQty];
        }

        return $adj;
    }

    /**
     * @param  array<int, list<array{to: int, qty: string}>>  $adj
     * @return array<string, string>
     */
    private function directedPathProductsFrom(int $from, array $adj): array
    {
        $factors = [(string) $from => '1'];
        $queue = [$from];
        while ($queue !== []) {
            $u = array_shift($queue);
            $fu = $factors[(string) $u];
            foreach ($adj[$u] ?? [] as $edge) {
                $v = $edge['to'];
                $key = (string) $v;
                $next = bcmul($fu, $edge['qty'], 10);
                if (! isset($factors[$key])) {
                    $factors[$key] = $next;
                    $queue[] = $v;
                } elseif (bccomp($factors[$key], $next, 8) !== 0) {
                    throw ValidationException::withMessages([
                        'product_unit_conversions' => [__('units.conversion_conflicting_paths')],
                    ]);
                }
            }
        }

        return $factors;
    }

    /**
     * @param  array<int, list<array{to: int, qty: string}>>  $adj
     */
    private function existsDirectedPath(int $start, int $target, array $adj): bool
    {
        $visited = [];
        $queue = [$start];
        $visited[$start] = true;
        while ($queue !== []) {
            $u = array_shift($queue);
            foreach ($adj[$u] ?? [] as $edge) {
                $v = $edge['to'];
                if ($v === $target) {
                    return true;
                }
                if (! isset($visited[$v])) {
                    $visited[$v] = true;
                    $queue[] = $v;
                }
            }
        }

        return false;
    }

    private function normalizeDecimal(string $value): string
    {
        return $this->packQuantity->normalizePackQuantity($value);
    }
}
