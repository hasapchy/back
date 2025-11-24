<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductImageService
{
    /**
     * Загрузить изображение товара
     *
     * @param Product $product
     * @param UploadedFile $file
     * @return string
     */
    public function uploadImage(Product $product, UploadedFile $file): string
    {
        return $file->store('products', 'public');
    }

    /**
     * Обновить изображение товара
     *
     * @param Product $product
     * @param UploadedFile|null $file
     * @return string|null
     */
    public function updateImage(Product $product, ?UploadedFile $file): ?string
    {
        if ($file) {
            if ($product->image) {
                $this->deleteImage($product);
            }
            return $this->uploadImage($product, $file);
        }

        return null;
    }

    /**
     * Удалить изображение товара
     *
     * @param Product $product
     * @return bool
     */
    public function deleteImage(Product $product): bool
    {
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            return Storage::disk('public')->delete($product->image);
        }

        return false;
    }
}

