<?php

namespace App\Services;

class AppVersionPresenter
{
    /**
     * @return array<int, array{version: string, released_at: string|null, platform: string, notes: array<int, string>}>
     */
    public function presentAll(?string $platform = null): array
    {
        $platform = $this->normalizePlatform($platform);
        $versions = config('app_versions.versions', []);

        return array_values(array_map(
            fn (array $version) => $this->presentOne($version, $platform),
            $versions
        ));
    }

    /**
     * @return array{version: string, released_at: string|null, platform: string, notes: array<int, string>}
     */
    public function presentOne(array $version, string $platform): array
    {
        return [
            'version' => (string) ($version['version'] ?? ''),
            'released_at' => isset($version['released_at']) ? (string) $version['released_at'] : null,
            'platform' => $platform,
            'notes' => $this->resolvePlatformNotes($version, $platform),
        ];
    }

    private function normalizePlatform(?string $platform): string
    {
        $allowed = config('app_versions.platforms', ['web', 'mobile']);
        $default = config('app_versions.default_platform', 'web');

        if ($platform === null || $platform === '') {
            return $default;
        }

        return in_array($platform, $allowed, true) ? $platform : $default;
    }

    /**
     * @return array<int, string>
     */
    private function resolvePlatformNotes(array $version, string $platform): array
    {
        $platformNotes = $version['platforms'][$platform]['notes'] ?? null;

        if ($platform === 'web') {
            if (is_array($platformNotes)) {
                return array_values($platformNotes);
            }

            return is_array($version['notes'] ?? null) ? array_values($version['notes']) : [];
        }

        if (is_array($platformNotes)) {
            return array_values($platformNotes);
        }

        return $this->resolvePlatformNotes($version, 'web');
    }
}
