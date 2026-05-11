<?php

namespace App\Services\Timeline;

use App\Services\CacheService;

class TimelineCache
{
    public static function key(string $apiType, int $id, ?int $companyId = null): string
    {
        if ($companyId !== null && $companyId > 0) {
            return "timeline_v2_{$apiType}_{$id}_{$companyId}";
        }
        return "timeline_v2_{$apiType}_{$id}";
    }

    public static function forget(string $apiType, int $id, ?int $companyId = null): void
    {
        if ($companyId !== null && $companyId > 0) {
            CacheService::forget(self::key($apiType, $id, $companyId));
        }

        CacheService::forget(self::key($apiType, $id));
        CacheService::invalidateByLike("timeline_v2_{$apiType}_{$id}_%");
    }
}
