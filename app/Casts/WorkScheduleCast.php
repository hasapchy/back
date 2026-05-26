<?php

namespace App\Casts;

use App\Support\WorkScheduleNormalizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class WorkScheduleCast implements CastsAttributes
{
    /**
     * @return array<int, array{enabled: bool, start: string, end: string}>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array{enabled: bool, start: string, end: string}>|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return WorkScheduleNormalizer::normalize($value);
    }
}
