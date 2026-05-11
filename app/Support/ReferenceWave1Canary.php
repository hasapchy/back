<?php

namespace App\Support;

final class ReferenceWave1Canary
{
    /**
     * @param  int|null  $companyId  Контекст компании из запроса; null — эндпоинты без привязки к компании
     */
    public static function useReferenceAllPayload(?int $companyId): bool
    {
        if (! config('features.reference_wave1')) {
            return false;
        }

        $canary = config('reference_contracts.canary', []);
        $canaryEnabled = (bool) ($canary['enabled'] ?? false);

        if (! $canaryEnabled) {
            return true;
        }

        if ($companyId === null) {
            return (bool) ($canary['unscoped_reference_all'] ?? true);
        }

        $ids = array_values(array_unique(array_map('intval', $canary['company_ids'] ?? [])));

        if ($ids === []) {
            return false;
        }

        return in_array((int) $companyId, $ids, true);
    }
}
