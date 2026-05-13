<?php

namespace App\Services;

use App\Models\UnitConversion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UnitConversionGraphService
{
    /**
     * @return array<int, list<array{to: int, qty: string}>>
     */
    private function buildAdjacency(int $companyId, ?int $ignoreConversionId): array
    {
        $query = UnitConversion::query()->where('company_id', $companyId);
        if ($ignoreConversionId !== null) {
            $query->where('id', '!=', $ignoreConversionId);
        }
        $adj = [];
        foreach ($query->get(['parent_unit_id', 'child_unit_id', 'quantity']) as $row) {
            $p = $row->parent_unit_id;
            $c = $row->child_unit_id;
            $qty = $this->normalizeDecimal((string) $row->quantity);
            $adj[$p] ??= [];
            $adj[$p][] = ['to' => $c, 'qty' => $qty];
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
                        'child_unit_id' => [__('units.conversion_conflicting_paths')],
                    ]);
                }
            }
        }

        return $factors;
    }

    /**
     * @throws ValidationException
     */
    public function assertConversionAllowed(int $companyId, int $parentId, int $childId, string $quantity, ?int $ignoreConversionId = null): void
    {
        if ($parentId === $childId) {
            throw ValidationException::withMessages([
                'child_unit_id' => [__('units.conversion_parent_equals_child')],
            ]);
        }
        $qty = $this->normalizeDecimal($quantity);
        if (bccomp($qty, '0', 8) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => [__('units.conversion_quantity_positive')],
            ]);
        }

        $reverseExists = UnitConversion::query()
            ->where('company_id', $companyId)
            ->where('parent_unit_id', $childId)
            ->where('child_unit_id', $parentId)
            ->when($ignoreConversionId !== null, fn ($q) => $q->where('id', '!=', $ignoreConversionId))
            ->exists();
        if ($reverseExists) {
            throw ValidationException::withMessages([
                'child_unit_id' => [__('units.conversion_reverse_exists')],
            ]);
        }

        $adjWithoutNew = $this->buildAdjacency($companyId, $ignoreConversionId);
        if ($this->existsDirectedPath($childId, $parentId, $adjWithoutNew)) {
            throw ValidationException::withMessages([
                'child_unit_id' => [__('units.conversion_cycle')],
            ]);
        }

        $factors = $this->directedPathProductsFrom($parentId, $adjWithoutNew);
        if (isset($factors[(string) $childId]) && bccomp($factors[(string) $childId], $qty, 8) !== 0) {
            throw ValidationException::withMessages([
                'quantity' => [__('units.conversion_path_quantity_mismatch')],
            ]);
        }
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

    /**
     * Нормализует строку десятичного числа для сравнений и сохранения.
     *
     * @param  string  $value  Строка количества из запроса или БД
     * @return string Нормализованное десятичное значение без лишних нулей в дробной части
     */
    public function normalizePackQuantity(string $value): string
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

    private function normalizeDecimal(string $value): string
    {
        return $this->normalizePackQuantity($value);
    }

    /**
     * @param  Collection<int, UnitConversion>  $edges
     * @return array<int, array<int, array{qty: string, is_down: bool}>>
     */
    public function buildUndirectedAdjacencyForPresentation(Collection $edges): array
    {
        $adj = [];
        foreach ($edges as $row) {
            $p = $row->parent_unit_id;
            $c = $row->child_unit_id;
            $qty = $this->normalizeDecimal((string) $row->quantity);
            $adj[$p] ??= [];
            $adj[$c] ??= [];
            $adj[$p][$c] = ['qty' => $qty, 'is_down' => true];
            $adj[$c][$p] = ['qty' => $qty, 'is_down' => false];
        }

        return $adj;
    }
}
