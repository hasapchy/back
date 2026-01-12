<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NewsImageService
{
    /**
     * Максимальный размер изображения в байтах (10 МБ)
     */
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

    /**
     * Обрабатывает HTML контент, извлекая base64 изображения и сохраняя их как файлы
     *
     * @param  string  $html  HTML контент с base64 изображениями
     * @param  int|null  $newsId  ID новости (для организации файлов)
     * @return string HTML контент с замененными URL на изображения
     */
    public function processImages(string $html, ?int $newsId = null): string
    {
        // Паттерн для поиска base64 изображений
        $pattern = '/<img[^>]+src=["\']data:image\/([^;]+);base64,([^"\']+)["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) use ($newsId) {
            $imageType = $matches[1]; // png, jpeg, gif и т.д.
            $base64Data = $matches[2];
            $fullMatch = $matches[0]; // Полный тег <img>

            // Декодируем base64
            $imageData = base64_decode($base64Data, true);

            if ($imageData === false) {
                // Если не удалось декодировать, возвращаем оригинальный тег
                return $fullMatch;
            }

            // Проверяем размер изображения
            $imageSize = strlen($imageData);
            if ($imageSize > self::MAX_IMAGE_SIZE) {
                // Изображение слишком большое, пропускаем его
                return $fullMatch;
            }

            // Валидируем MIME-тип изображения
            if (! $this->validateImageMimeType($imageData, $imageType)) {
                // Неверный тип изображения, пропускаем
                return $fullMatch;
            }

            // Генерируем уникальное имя файла
            $fileName = Str::random(40).'.'.$this->normalizeImageType($imageType);

            // Определяем путь для сохранения
            $directory = 'news/images';
            if ($newsId) {
                $directory .= '/'.$newsId;
            }

            // Сохраняем файл
            $path = $directory.'/'.$fileName;
            Storage::disk('public')->put($path, $imageData);

            // Генерируем URL для изображения
            $url = Storage::disk('public')->url($path);

            // Заменяем data:image в src атрибуте на URL
            $newTag = preg_replace(
                '/src=["\']data:image\/[^"\']+["\']/i',
                'src="'.$url.'"',
                $fullMatch
            );

            return $newTag;
        }, $html);
    }

    /**
     * Валидирует MIME-тип изображения
     *
     * @param  string  $imageData  Декодированные данные изображения
     * @param  string  $declaredType  Заявленный тип изображения
     * @return bool true если тип валиден, false в противном случае
     */
    private function validateImageMimeType(string $imageData, string $declaredType): bool
    {
        // Получаем реальный MIME-тип через getimagesize
        $imageInfo = @getimagesizefromstring($imageData);

        if ($imageInfo === false) {
            return false;
        }

        $actualMime = $imageInfo['mime'] ?? '';

        // Маппинг заявленных типов на MIME-типы
        $mimeMapping = [
            'jpeg' => ['image/jpeg'],
            'jpg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg+xml' => ['image/svg+xml', 'image/svg'],
        ];

        $declaredTypeLower = strtolower($declaredType);
        $expectedMimes = $mimeMapping[$declaredTypeLower] ?? [];

        // Проверяем, соответствует ли реальный MIME-тип заявленному
        if (empty($expectedMimes)) {
            // Неизвестный тип, разрешаем только стандартные типы изображений
            return in_array($actualMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        }

        return in_array($actualMime, $expectedMimes);
    }

    /**
     * Нормализует тип изображения
     *
     * @param  string  $type  Тип изображения
     * @return string Нормализованный тип
     */
    private function normalizeImageType(string $type): string
    {
        $type = strtolower($type);

        // Маппинг типов
        $mapping = [
            'jpeg' => 'jpg',
            'jpg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            'svg+xml' => 'svg',
        ];

        return $mapping[$type] ?? 'jpg';
    }

    /**
     * Удаляет все изображения, связанные с новостью
     *
     * @param  int  $newsId  ID новости
     */
    public function deleteNewsImages(int $newsId): void
    {
        $directory = 'news/images/'.$newsId;

        if (Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->deleteDirectory($directory);
        }
    }

    /**
     * Извлекает все URL изображений из HTML контента
     *
     * @param  string  $html  HTML контент
     * @return array Массив URL изображений
     */
    public function extractImageUrls(string $html): array
    {
        $urls = [];
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

        preg_match_all($pattern, $html, $matches);

        if (! empty($matches[1])) {
            foreach ($matches[1] as $url) {
                // Игнорируем base64 изображения
                if (! str_starts_with($url, 'data:image/')) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Удаляет неиспользуемые изображения из HTML контента
     *
     * @param  string  $oldHtml  Старый HTML контент
     * @param  string  $newHtml  Новый HTML контент
     * @param  int  $newsId  ID новости
     */
    public function cleanupUnusedImages(string $oldHtml, string $newHtml, int $newsId): void
    {
        $oldUrls = $this->extractImageUrls($oldHtml);
        $newUrls = $this->extractImageUrls($newHtml);

        $deletedUrls = array_diff($oldUrls, $newUrls);

        foreach ($deletedUrls as $url) {
            // Извлекаем путь из URL
            $path = str_replace(Storage::disk('public')->url(''), '', $url);
            $path = ltrim($path, '/');

            // Проверяем, что файл принадлежит этой новости
            if (str_starts_with($path, 'news/images/'.$newsId.'/') || str_starts_with($path, 'news/images/')) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * Организует изображения по ID новости (перемещает из общей папки в папку новости)
     *
     * @param  string  $html  HTML контент с URL изображений
     * @param  int  $newsId  ID новости
     * @return string Обновленный HTML с новыми URL
     */
    public function organizeImagesByNewsId(string $html, int $newsId): string
    {
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';
        $storageUrl = Storage::disk('public')->url('');

        return preg_replace_callback($pattern, function ($matches) use ($newsId, $storageUrl) {
            $url = $matches[1];
            $fullMatch = $matches[0];

            // Пропускаем base64 изображения
            if (str_starts_with($url, 'data:image/')) {
                return $fullMatch;
            }

            // Извлекаем путь из URL
            $path = str_replace($storageUrl, '', $url);
            $path = ltrim($path, '/');

            // Если изображение уже в правильной папке, не трогаем
            if (str_starts_with($path, 'news/images/'.$newsId.'/')) {
                return $fullMatch;
            }

            // Если изображение в общей папке news/images, перемещаем в папку новости
            if (str_starts_with($path, 'news/images/') && ! str_starts_with($path, 'news/images/'.$newsId.'/')) {
                $fileName = basename($path);
                $newPath = 'news/images/'.$newsId.'/'.$fileName;

                // Перемещаем файл
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->move($path, $newPath);
                    $newUrl = Storage::disk('public')->url($newPath);

                    // Заменяем URL в теге
                    return str_replace($url, $newUrl, $fullMatch);
                }
            }

            return $fullMatch;
        }, $html);
    }
}
