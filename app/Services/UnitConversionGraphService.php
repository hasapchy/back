<?php

namespace App\Services;

use Illuminate\Support\Collection;

class UnitConversionGraphService
{
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

    /**
     * @param  Collection<int, object>  $edges
     * @return array<int, array<int, array{qty: string, is_down: bool}>>
     */
    public function buildUndirectedAdjacencyForPresentation(Collection $edges): array
    {
        $adj = [];
        foreach ($edges as $row) {
            $p = (int) $row->parent_unit_id;
            $c = (int) $row->child_unit_id;
            $qty = $this->normalizePackQuantity((string) $row->quantity);
            $adj[$p] ??= [];
            $adj[$c] ??= [];
            $adj[$p][$c] = ['qty' => $qty, 'is_down' => true];
            $adj[$c][$p] = ['qty' => $qty, 'is_down' => false];
        }

        return $adj;
    }
}
