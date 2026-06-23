<?php

namespace App\Support;

use App\Models\DriveFolder;

final class DriveSystemFolders
{
    public const KEY_PROJECTS = 'projects';

    /**
     * @return array<string, array{name: string, icon: string, icon_color: string}>
     */
    public static function definitions(): array
    {
        $definitions = config('drive.system_folders');

        return is_array($definitions) ? $definitions : [];
    }

    /**
     * @return array{name?: string, icon?: string, icon_color?: string}
     */
    public static function definition(string $key): array
    {
        $definition = self::definitions()[$key] ?? [];

        return is_array($definition) ? $definition : [];
    }

    public static function isSystemFolder(DriveFolder $folder): bool
    {
        return self::systemKey($folder) !== null;
    }

    public static function isProjectsContainer(DriveFolder $folder): bool
    {
        return self::systemKey($folder) === self::KEY_PROJECTS;
    }

    public static function systemKey(DriveFolder $folder): ?string
    {
        $key = strtolower(trim((string) ($folder->system_key ?? '')));

        return $key !== '' ? $key : null;
    }

    public static function displayIcon(?string $icon, ?string $systemKey): string
    {
        if ($systemKey !== null) {
            $configured = (string) (self::definition($systemKey)['icon'] ?? '');
            if ($configured !== '') {
                return DriveFolderAppearance::resolveIcon($configured);
            }
        }

        return DriveFolderAppearance::resolveIcon($icon);
    }
}
