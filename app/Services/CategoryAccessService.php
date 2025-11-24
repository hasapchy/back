<?php

namespace App\Services;

use App\Models\User;
use App\Models\Category;
use App\Models\CategoryUser;

class CategoryAccessService
{
    /**
     * Проверить доступ пользователя к категории
     *
     * @param User $user
     * @param int $categoryId
     * @param int|null $companyId
     * @return bool
     */
    public function canAccessCategory(User $user, int $categoryId, ?int $companyId = null): bool
    {
        if (!$user->hasRole(config('basement.worker_role'))) {
            return true;
        }

        $userCategoryIds = $this->getUserCategories($user, $companyId);

        return in_array($categoryId, $userCategoryIds);
    }

    /**
     * Получить список категорий пользователя
     *
     * @param User $user
     * @param int|null $companyId
     * @return array
     */
    public function getUserCategories(User $user, ?int $companyId = null): array
    {
        $userCategoryIds = CategoryUser::where('user_id', $user->id)
            ->pluck('category_id')
            ->toArray();

        if ($companyId) {
            $companyCategoryIds = Category::where('company_id', $companyId)
                ->pluck('id')
                ->toArray();
            $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
        }

        return array_values($userCategoryIds);
    }

    /**
     * Нормализовать ID категории для basement worker
     *
     * @param User $user
     * @param int|null $categoryId
     * @param int|null $companyId
     * @return int|null
     */
    public function normalizeCategoryIdForWorker(User $user, ?int $categoryId, ?int $companyId = null): ?int
    {
        if (!$user->hasRole(config('basement.worker_role'))) {
            return $categoryId;
        }

        $userCategoryIds = $this->getUserCategories($user, $companyId);

        if (!$categoryId) {
            return !empty($userCategoryIds) ? $userCategoryIds[0] : null;
        }

        if (!in_array($categoryId, $userCategoryIds)) {
            return null;
        }

        return $categoryId;
    }
}

