<?php

namespace App\Http\Validation;

class ProfileWallpaperRules
{
    /**
     * @return array<int, string>
     */
    public static function allowedKeys(): array
    {
        $themes = config('profile_wallpapers.themes', []);

        return array_keys(is_array($themes) ? $themes : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateRules(): array
    {
        $keys = self::allowedKeys();

        return [
            'profile_wallpaper' => empty($keys)
                ? 'nullable'
                : 'nullable|string|in:'.implode(',', $keys),
        ];
    }
}
