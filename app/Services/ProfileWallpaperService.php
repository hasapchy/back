<?php

namespace App\Services;

use App\Http\Validation\ProfileWallpaperRules;

class ProfileWallpaperService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        $items = [
            [
                'id' => 'default',
                'label_key' => 'profileWallpaperDefault',
                'supported_modes' => [],
                'files' => [],
                'previews' => [],
            ],
        ];

        $themes = config('profile_wallpapers.themes', []);
        if (! is_array($themes)) {
            return $items;
        }

        foreach ($themes as $id => $theme) {
            if (! is_array($theme)) {
                continue;
            }

            $supportedModes = array_values(array_filter(
                $theme['supported_modes'] ?? [],
                static fn ($mode) => in_array($mode, ['light', 'dark'], true)
            ));

            $items[] = [
                'id' => (string) $id,
                'label_key' => (string) ($theme['label_key'] ?? $id),
                'supported_modes' => $supportedModes,
                'files' => $this->mapModeFiles($theme['files'] ?? [], $supportedModes),
                'previews' => $this->mapModeFiles($theme['previews'] ?? [], $supportedModes),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<int, string>  $supportedModes
     * @return array<string, string>
     */
    private function mapModeFiles(array $files, array $supportedModes): array
    {
        $mapped = [];

        foreach ($supportedModes as $mode) {
            $filename = $files[$mode] ?? null;
            if (! is_string($filename) || $filename === '') {
                continue;
            }
            $mapped[$mode] = $this->buildPublicUrl($filename);
        }

        return $mapped;
    }

    public function isValidKey(?string $key): bool
    {
        if ($key === null || $key === '') {
            return true;
        }

        return in_array($key, ProfileWallpaperRules::allowedKeys(), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTheme(string $wallpaperId): ?array
    {
        foreach ($this->catalog() as $item) {
            if (($item['id'] ?? null) === $wallpaperId) {
                return $item;
            }
        }

        return null;
    }

    public function resolveImageUrl(string $wallpaperId, string $uiMode): ?string
    {
        if ($wallpaperId === '' || $wallpaperId === 'default') {
            return null;
        }

        $theme = $this->findTheme($wallpaperId);
        if ($theme === null) {
            return null;
        }

        $supportedModes = $theme['supported_modes'] ?? [];
        if (! in_array($uiMode, $supportedModes, true)) {
            return null;
        }

        $files = $theme['files'] ?? [];

        return is_array($files) ? ($files[$uiMode] ?? null) : null;
    }

    private function buildPublicUrl(string $filename): string
    {
        $basePath = (string) config('profile_wallpapers.base_path', '/wallpapers');
        $basePath = '/'.trim($basePath, '/');

        return $basePath.'/'.ltrim($filename, '/');
    }
}
