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
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $normalized = WorkScheduleNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }

        return json_encode($normalized, JSON_FORCE_OBJECT);
    }
}
