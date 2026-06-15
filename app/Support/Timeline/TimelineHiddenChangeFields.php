<?php

namespace App\Support\Timeline;

final class TimelineHiddenChangeFields
{
  /**
   * @return list<string>
   */
    private static function prefixes(): array
    {
        return ['def_', 'rep_', 'orig_'];
    }

    /**
     * @return bool
     */
    public static function shouldSkip(string $key): bool
    {
        foreach (self::prefixes() as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
