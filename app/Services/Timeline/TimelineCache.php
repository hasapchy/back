<?php

namespace App\Services\Timeline;

use App\Services\CacheService;

class TimelineCache
{
    public static function key(string $apiType, int $id, ?int $companyId = null): string
    {
        if ($companyId !== null && $companyId > 0) {
            return "timeline_v3_{$apiType}_{$id}_{$companyId}";
        }

        return "timeline_v3_{$apiType}_{$id}";
    }

    /**
     * @param string $apiType
     * @param int $id
     * @param int|null $companyId
     * @return string
     */
    public static function page1Key(string $apiType, int $id, ?int $companyId = null): string
    {
        return self::key($apiType, $id, $companyId).'_page1';
    }

    /**
     * @param string $apiType
     * @param int $id
     * @param int|null $companyId
     * @return void
     */
    public static function forget(string $apiType, int $id, ?int $companyId = null): void
    {
        if ($companyId !== null && $companyId > 0) {
            CacheService::forget(self::key($apiType, $id, $companyId));
            CacheService::forget(self::page1Key($apiType, $id, $companyId));
        }

        CacheService::forget(self::key($apiType, $id));
        CacheService::forget(self::page1Key($apiType, $id));
        CacheService::invalidateByLike("timeline_v3_{$apiType}_{$id}_%");
    }
}
