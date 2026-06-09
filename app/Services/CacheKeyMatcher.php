<?php

namespace App\Services;

class CacheKeyMatcher
{
    /**
     * @param  string  $key  Application cache key without Laravel prefix
     * @param  string  $likePattern  SQL LIKE pattern
     * @param  int|null  $companyId  Company scope for invalidation
     */
    public static function matches(string $key, string $likePattern, ?int $companyId = null): bool
    {
        if (preg_match(self::likeToRegex($likePattern), $key) !== 1) {
            return false;
        }

        if ($companyId === null) {
            return true;
        }

        $companySuffix = "_{$companyId}";

        return str_ends_with($key, $companySuffix)
            || str_contains($key, "{$companySuffix}_");
    }

    /**
     * @param  string  $pattern  SQL LIKE pattern
     */
    protected static function likeToRegex(string $pattern): string
    {
        $parts = preg_split('/([%_])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $regex = '';

        foreach ($parts as $part) {
            if ($part === '%') {
                $regex .= '.*';
            } elseif ($part === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($part, '/');
            }
        }

        return '/^'.$regex.'$/';
    }
}
