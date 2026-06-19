<?php

namespace App\Http\Validation;

use InvalidArgumentException;

class UserUiPreferencesRules
{
    /**
     * @return array<string, mixed>
     */
    public static function patchRules(): array
    {
        return [
            'vuex' => 'sometimes|nullable|array',
            'ls' => 'sometimes|nullable|array',
        ];
    }

    /**
     * @return list<string>
     */
    public static function vuexFields(): array
    {
        $fields = config('ui_preferences.vuex_fields', []);

        return is_array($fields) ? array_values($fields) : [];
    }

    /**
     * @param  array<string, mixed>|null  $vuex
     * @return array<string, mixed>
     */
    public static function filterVuex(?array $vuex): array
    {
        if ($vuex === null || $vuex === []) {
            return [];
        }

        $allowed = array_flip(self::vuexFields());
        $filtered = [];
        foreach ($vuex as $key => $value) {
            if (! isset($allowed[$key])) {
                throw new InvalidArgumentException(__('api.ui_preferences.unknown_vuex_field', ['field' => (string) $key]));
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>|null  $ls
     * @return array<string, mixed>
     */
    public static function flattenLsPatch(?array $ls): array
    {
        if ($ls === null || $ls === []) {
            return [];
        }

        $flat = [];
        $walk = function (array $items, string $prefix = '') use (&$walk, &$flat): void {
            foreach ($items as $key => $value) {
                $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $walk($value, $fullKey);

                    continue;
                }
                $flat[$fullKey] = $value;
            }
        };
        $walk($ls);

        return $flat;
    }

    /**
     * @param  array<string, mixed>|null  $ls
     * @param  int|null  $userId
     * @return array<string, string>
     */
    public static function filterLs(?array $ls, ?int $userId): array
    {
        if ($ls === null || $ls === []) {
            return [];
        }

        $filtered = [];
        foreach ($ls as $key => $value) {
            $key = (string) $key;
            if (! self::isAllowedLsKey($key, $userId)) {
                throw new InvalidArgumentException(__('api.ui_preferences.unknown_ls_key', ['key' => $key]));
            }
            if ($value === null) {
                continue;
            }
            if (! is_string($value) && ! is_numeric($value) && ! is_bool($value)) {
                throw new InvalidArgumentException(__('api.ui_preferences.invalid_ls_value', ['key' => $key]));
            }
            $filtered[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $filtered;
    }

    /**
     * @param  string  $key
     * @param  int|null  $userId
     * @return bool
     */
    public static function isAllowedLsKey(string $key, ?int $userId): bool
    {
        $exact = config('ui_preferences.ls_exact_keys', []);
        if (is_array($exact) && in_array($key, $exact, true)) {
            return true;
        }

        foreach (config('ui_preferences.ls_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)) {
                return true;
            }
        }

        foreach (config('ui_preferences.ls_prefixes_with_user_id', []) as $prefix) {
            if (! is_string($prefix) || $prefix === '' || ! str_starts_with($key, $prefix)) {
                continue;
            }
            if ($userId === null || $userId < 1) {
                return false;
            }

            return $key === $prefix.$userId;
        }

        return false;
    }
}
