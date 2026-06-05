<?php

namespace App\Support;

final class DriveFolderAppearance
{
    public static function resolveIcon(?string $icon): string
    {
        $value = trim((string) $icon);
        if ($value === '') {
            $icons = config('drive.folder_icons');

            return is_array($icons) && isset($icons[0]) ? (string) $icons[0] : 'fas fa-folder';
        }

        return $value;
    }

    public static function resolveIconColor(?string $color): string
    {
        $value = trim((string) $color);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1) {
            return $value;
        }

        return (string) config('drive.folder_icon_color_default');
    }
}
