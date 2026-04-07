<?php

namespace App\Services\Timeline;

use App\Services\CacheService;

class TimelineCache
{
    public static function key(string $apiType, int $id): string
    {
        return "timeline_v2_{$apiType}_{$id}";
    }

    public static function forget(string $apiType, int $id): void
    {
        CacheService::forget(self::key($apiType, $id));
    }
}
