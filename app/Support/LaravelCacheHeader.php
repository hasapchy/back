<?php

namespace App\Support;

use Illuminate\Http\Request;

class LaravelCacheHeader
{
    public const ATTRIBUTE = 'laravel_cache_status';

    public const HIT = 'HIT';

    public const MISS = 'MISS';

    /**
     * @return string|null
     */
    public static function fromRequest(?Request $request = null): ?string
    {
        $request = self::resolveRequest($request);
        if ($request === null) {
            return null;
        }

        $status = $request->attributes->get(self::ATTRIBUTE);
        if ($status !== self::HIT && $status !== self::MISS) {
            return null;
        }

        return $status;
    }

    /**
     * @return void
     */
    public static function recordHit(?Request $request = null): void
    {
        $request = self::resolveRequest($request);
        if ($request === null) {
            return;
        }

        if ($request->attributes->get(self::ATTRIBUTE) === self::MISS) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, self::HIT);
    }

    /**
     * @return void
     */
    public static function recordMiss(?Request $request = null): void
    {
        $request = self::resolveRequest($request);
        if ($request === null) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, self::MISS);
    }

    /**
     * @return Request|null
     */
    protected static function resolveRequest(?Request $request): ?Request
    {
        if ($request !== null) {
            return $request;
        }

        if (! app()->bound('request')) {
            return null;
        }

        $resolved = request();

        return $resolved instanceof Request ? $resolved : null;
    }
}
