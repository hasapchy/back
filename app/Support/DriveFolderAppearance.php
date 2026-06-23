<?php

namespace App\Support;

final class DriveFolderAppearance
{
    public const PROJECT_LINKED_ICON = 'fas fa-link';

    public static function projectLinkedIcon(): string
    {
        return self::PROJECT_LINKED_ICON;
    }

    public static function resolveIcon(?string $icon): string
    {
        $value = trim((string) $icon);
        if ($value === '') {
            $icons = config('drive.folder_icons');

            return is_array($icons) && isset($icons[0]) ? (string) $icons[0] : 'fas fa-folder';
        }

        return $value;
    }

    public static function resolveDisplayIcon(?string $icon, ?int $projectId, ?string $systemKey = null): string
    {
        if ($systemKey !== null) {
            return DriveSystemFolders::displayIcon($icon, $systemKey);
        }

        $resolved = self::resolveIcon($icon);
        if ($projectId && $resolved === self::PROJECT_LINKED_ICON) {
            return self::resolveIcon(null);
        }

        return $resolved;
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
