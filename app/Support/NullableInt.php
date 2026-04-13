<?php

namespace App\Support;

final class NullableInt
{
    /**
     * @param  mixed  $value
     * @return int|null
     */
    public static function fromRequest(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
