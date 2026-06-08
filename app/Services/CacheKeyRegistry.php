<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheKeyRegistry
{
    /**
     * @return string
     */
    public static function indexPath(): string
    {
        return storage_path('framework/cache/.key-index.json');
    }

    /**
     * @param  string  $key  Application cache key
     */
    public static function register(string $key): void
    {
        if ($key === '') {
            return;
        }

        self::mutateIndex(function (array $keys) use ($key): array {
            $keys[$key] = true;

            return $keys;
        });
    }

    /**
     * @param  string  $key  Application cache key
     */
    public static function unregister(string $key): void
    {
        if ($key === '') {
            return;
        }

        self::mutateIndex(function (array $keys) use ($key): array {
            unset($keys[$key]);

            return $keys;
        });
    }

    /**
     * @param  string  $likePattern  SQL LIKE pattern
     * @param  int|null  $companyId  Company scope for invalidation
     * @return list<string>
     */
    public static function matchKeys(string $likePattern, ?int $companyId = null): array
    {
        $matched = [];

        foreach (array_keys(self::readIndex()) as $key) {
            if (CacheKeyMatcher::matches($key, $likePattern, $companyId)) {
                $matched[] = $key;
            }
        }

        return $matched;
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        $path = self::indexPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }

        $tempPath = $path.'.tmp';
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }
    }

    /**
     * @return int Number of removed index entries
     */
    public static function prune(): int
    {
        $removed = 0;

        self::mutateIndex(function (array $keys) use (&$removed): array {
            foreach (array_keys($keys) as $key) {
                if (! Cache::has($key)) {
                    unset($keys[$key]);
                    $removed++;
                }
            }

            return $keys;
        });

        return $removed;
    }

    /**
     * @return array<string, bool>
     */
    protected static function readIndex(): array
    {
        $path = self::indexPath();

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return [];
            }

            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return self::decodeIndex($contents);
    }

    /**
     * @return array<string, bool>
     */
    protected static function decodeIndex(string|false $contents): array
    {
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && $key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  callable(array<string, bool>): array<string, bool>  $callback
     */
    protected static function mutateIndex(callable $callback): void
    {
        $path = self::indexPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create cache registry directory: {$directory}");
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open cache registry file: {$path}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock cache registry file: {$path}");
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $keys = self::decodeIndex($contents);
            $keys = $callback($keys);
            $encoded = json_encode($keys, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new \RuntimeException('Unable to encode cache registry index.');
            }

            rewind($handle);
            ftruncate($handle, 0);

            if (fwrite($handle, $encoded) === false) {
                throw new \RuntimeException("Unable to write cache registry file: {$path}");
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
