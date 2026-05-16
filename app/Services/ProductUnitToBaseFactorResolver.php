<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ProductUnitToBaseFactorResolver
{
    public function __construct(
        private UnitConversionGraphService $graphService
    ) {
    }

    /**
     * @param  Collection<int, object>  $edges
     */
    public function factorAlternateToBase(Collection $edges, int $baseUnitId, int $alternateUnitId): ?string
    {
        if ($alternateUnitId === $baseUnitId) {
            return '1';
        }

        $map = $this->baseUnitsPerOneMap($edges, $baseUnitId);
        $key = (string) $alternateUnitId;
        if (! isset($map[$key])) {
            return null;
        }

        return $this->trimZeros($map[$key]);
    }

    /**
     * @param  Collection<int, object>  $edges
     * @return array<string, string> unit_id => base units per one unit of that id
     */
    public function baseUnitsPerOneMap(Collection $edges, int $baseUnitId): array
    {
        if ($edges->isEmpty()) {
            return [(string) $baseUnitId => '1'];
        }

        $adj = $this->graphService->buildUndirectedAdjacencyForPresentation($edges);
        if (! isset($adj[$baseUnitId]) || $adj[$baseUnitId] === []) {
            return [(string) $baseUnitId => '1'];
        }

        $baseUnitsPerOne = [(string) $baseUnitId => '1'];
        $queue = [$baseUnitId];
        while ($queue !== []) {
            $u = array_shift($queue);
            $tu = $baseUnitsPerOne[(string) $u];
            foreach ($adj[$u] ?? [] as $v => $meta) {
                $vk = (string) $v;
                if (isset($baseUnitsPerOne[$vk])) {
                    continue;
                }
                $q = $meta['qty'];
                $baseUnitsPerOne[$vk] = $meta['is_down']
                    ? bcdiv($tu, $q, 30)
                    : bcmul($tu, $q, 30);
                $queue[] = $v;
            }
        }

        foreach ($baseUnitsPerOne as $uid => $factor) {
            $baseUnitsPerOne[$uid] = $this->trimZeros($factor);
        }

        return $baseUnitsPerOne;
    }

    private function trimZeros(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
