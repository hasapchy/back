<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UserPhotoService
{
    /**
     * Загрузить фото пользователя
     *
     * @param User $user
     * @param UploadedFile $file
     * @return string
     */
    public function uploadPhoto(User $user, UploadedFile $file): string
    {
        if ($user->photo) {
            $this->deletePhoto($user);
        }

        $photoName = time() . '_' . $file->getClientOriginalName();
        $file->storeAs('public/uploads/users', $photoName);

        return 'uploads/users/' . $photoName;
    }

    /**
     * Обновить фото пользователя
     *
     * @param User $user
     * @param UploadedFile|null $file
     * @return string|null
     */
    public function updatePhoto(User $user, ?UploadedFile $file): ?string
    {
        if ($file) {
            return $this->uploadPhoto($user, $file);
        }

        if ($user->photo) {
            $this->deletePhoto($user);
        }

        return null;
    }

    /**
     * Удалить фото пользователя
     *
     * @param User $user
     * @return bool
     */
    public function deletePhoto(User $user): bool
    {
        if ($user->photo && Storage::disk('public')->exists($user->photo)) {
            return Storage::disk('public')->delete($user->photo);
        }

        return false;
    }
}

