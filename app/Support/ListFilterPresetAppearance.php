<?php

namespace App\Support;

use InvalidArgumentException;

class ListFilterPresetAppearance
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function icons(): array
    {
        return [
            ['value' => 'fas fa-bookmark', 'label' => 'bookmark'],
            ['value' => 'fas fa-filter', 'label' => 'filter'],
            ['value' => 'fas fa-chart-line', 'label' => 'chart'],
            ['value' => 'fas fa-calendar-days', 'label' => 'calendar'],
            ['value' => 'fas fa-money-bill-wave', 'label' => 'money'],
            ['value' => 'fas fa-users', 'label' => 'users'],
            ['value' => 'fas fa-briefcase', 'label' => 'briefcase'],
            ['value' => 'fas fa-star', 'label' => 'star'],
            ['value' => 'fas fa-bolt', 'label' => 'bolt'],
            ['value' => 'fas fa-eye', 'label' => 'eye'],
            ['value' => 'fas fa-tag', 'label' => 'tag'],
            ['value' => 'fas fa-folder', 'label' => 'folder'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function colors(): array
    {
        return [
            '#3571A4',
            '#5CB85C',
            '#EE4F47',
            '#F59E0B',
            '#8B5CF6',
            '#EC4899',
            '#06B6D4',
            '#64748B',
            '#14B8A6',
            '#EAB308',
            '#F97316',
            '#6366F1',
        ];
    }

    /**
     * @return array{icons: array<int, array{value: string, label: string}>, colors: array<int, string>}
     */
    public static function schema(): array
    {
        return [
            'icons' => self::icons(),
            'colors' => self::colors(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function iconValues(): array
    {
        return array_column(self::icons(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function colorValues(): array
    {
        return self::colors();
    }

    public static function normalizeIcon(?string $icon): string
    {
        $value = trim((string) $icon);
        if ($value === '' || ! in_array($value, self::iconValues(), true)) {
            throw new InvalidArgumentException('Invalid preset icon');
        }

        return $value;
    }

    public static function normalizeColor(?string $color): string
    {
        $value = strtoupper(trim((string) $color));
        if ($value === '' || ! in_array($value, array_map('strtoupper', self::colorValues()), true)) {
            throw new InvalidArgumentException('Invalid preset color');
        }

        return $value;
    }
}
