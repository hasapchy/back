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

    /**
     * Положительный целочисленный идентификатор из query или null.
     *
     * @param  mixed  $value
     */
    public static function positiveOrNull(mixed $value): ?int
    {
        $id = self::fromRequest($value);

        return ($id !== null && $id > 0) ? $id : null;
    }
}
