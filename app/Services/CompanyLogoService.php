<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CompanyLogoService
{
    /**
     * Загрузить логотип компании
     *
     * @param Company $company
     * @param UploadedFile $file
     * @return string
     */
    public function uploadLogo(Company $company, UploadedFile $file): string
    {
        return $file->store('companies', 'public');
    }

    /**
     * Обновить логотип компании
     *
     * @param Company $company
     * @param UploadedFile|null $file
     * @return string|null
     */
    public function updateLogo(Company $company, ?UploadedFile $file): ?string
    {
        if ($file) {
            if ($company->logo && $company->logo !== 'logo.png') {
                $this->deleteLogo($company);
            }
            return $this->uploadLogo($company, $file);
        }

        return null;
    }

    /**
     * Удалить логотип компании
     *
     * @param Company $company
     * @return bool
     */
    public function deleteLogo(Company $company): bool
    {
        if ($company->logo && $company->logo !== 'logo.png' && Storage::disk('public')->exists($company->logo)) {
            return Storage::disk('public')->delete($company->logo);
        }

        return false;
    }
}

