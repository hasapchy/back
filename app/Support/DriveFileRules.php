<?php

namespace App\Support;

use App\Models\DriveFile;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

final class DriveFileRules
{
    /**
     * @return array<int, string>
     */
    public static function allowedExtensions(): array
    {
        return config('drive.allowed_file_extensions');
    }

    /**
     * @return array<int, string>
     */
    public static function imageExtensions(): array
    {
        return config('drive.image_extensions');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function allowedMimeTypesByExtension(): array
    {
        return config('drive.allowed_mime_types_by_extension');
    }

    public static function maxFileBytes(): int
    {
        return (int) config('drive.max_file_bytes');
    }

    public static function extensionFromDriveFile(DriveFile $file): string
    {
        $extension = strtolower(trim((string) $file->extension));
        if ($extension !== '') {
            return $extension;
        }

        return strtolower((string) pathinfo((string) $file->name, PATHINFO_EXTENSION));
    }

    public static function isAllowedUploadedFile(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '' || ! self::isAllowedExtension($extension)) {
            return false;
        }

        return self::isAllowedMimeForExtension($extension, self::resolveMimeType($file));
    }

    public static function isAllowedExtension(string $extension): bool
    {
        $normalized = strtolower(trim($extension));

        return $normalized !== '' && in_array($normalized, self::allowedExtensions(), true);
    }

    public static function isImageFile(DriveFile $file): bool
    {
        $extension = self::extensionFromDriveFile($file);
        if (in_array($extension, self::imageExtensions(), true)) {
            return true;
        }

        $mimeType = strtolower(trim((string) ($file->mime_type ?? '')));
        if ($mimeType === '') {
            return false;
        }

        return str_starts_with($mimeType, 'image/');
    }

    public static function unsupportedUploadMessage(UploadedFile $file): string
    {
        $name = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) pathinfo($name, PATHINFO_EXTENSION));
        $typeLabel = $extension !== '' ? '.'.$extension : 'unknown';

        return "Неподдерживаемый тип файла: {$name} ({$typeLabel})";
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicConfig(): array
    {
        return [
            'allowed_file_extensions' => self::allowedExtensions(),
            'image_extensions' => self::imageExtensions(),
            'max_file_bytes' => self::maxFileBytes(),
            'folder_icons' => config('drive.folder_icons'),
            'folder_icon_color_default' => config('drive.folder_icon_color_default'),
        ];
    }

    private static function resolveMimeType(UploadedFile $file): string
    {
        $clientMime = strtolower(trim((string) $file->getClientMimeType()));
        if ($clientMime !== '' && $clientMime !== 'application/octet-stream') {
            return $clientMime;
        }

        $path = $file->getRealPath();
        if ($path && is_file($path)) {
            $guessed = (new SymfonyFile($path))->getMimeType();

            return strtolower(trim((string) $guessed));
        }

        return $clientMime;
    }

    private static function isAllowedMimeForExtension(string $extension, string $mimeType): bool
    {
        $map = self::allowedMimeTypesByExtension();
        $allowedMimes = $map[$extension] ?? [];
        if ($allowedMimes === []) {
            return false;
        }

        if ($mimeType === '' || $mimeType === 'application/octet-stream') {
            return false;
        }

        return in_array($mimeType, $allowedMimes, true);
    }
}
