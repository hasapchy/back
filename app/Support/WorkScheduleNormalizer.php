<?php

namespace App\Support;

class WorkScheduleNormalizer
{
    /**
     * @param  mixed  $value
     * @return array<int, array{enabled: bool, start: string, end: string}>|null
     */
    public static function normalize(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $days = self::extractSevenDays($value);
        if ($days === null) {
            return null;
        }

        $result = [];
        foreach ($days as $index => $day) {
            $normalized = self::normalizeDay($day);
            if ($normalized === null) {
                return null;
            }
            $result[$index + 1] = $normalized;
        }

        return $result;
    }

    /**
     * @param  mixed  $raw
     * @return array<int, array{enabled: bool, start: string, end: string}>|null
     */
    public static function prepareInput(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return null;
        }

        return self::normalize($raw);
    }

    /**
     * @param  array<mixed>  $value
     * @return list<mixed>|null
     */
    private static function extractSevenDays(array $value): ?array
    {
        if (array_is_list($value) && count($value) >= 7) {
            return array_slice($value, 0, 7);
        }

        if (isset($value[1]) || isset($value['1'])) {
            $days = [];
            for ($d = 1; $d <= 7; $d++) {
                $days[] = $value[$d] ?? $value[(string) $d] ?? null;
            }

            return $days;
        }

        if (isset($value[0]) || isset($value['0'])) {
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $days[] = $value[$i] ?? $value[(string) $i] ?? null;
            }

            return $days;
        }

        return null;
    }

    /**
     * @return array{enabled: bool, start: string, end: string}|null
     */
    private static function normalizeDay(mixed $day): ?array
    {
        if (! is_array($day)) {
            return null;
        }

        return [
            'enabled' => filter_var($day['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'start' => (string) ($day['start'] ?? '09:00'),
            'end' => (string) ($day['end'] ?? '18:00'),
        ];
    }
}
