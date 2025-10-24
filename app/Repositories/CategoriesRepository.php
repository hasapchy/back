<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\CategoryUser;
use App\Models\Warehouse;
use App\Services\CacheService;

class CategoriesRepository
{
    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        return request()->header('X-Company-ID');
    }

    /**
     * Добавить фильтрацию по компании к запросу
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('categories.company_id', $companyId);
        } else {
            // Если компания не выбрана, показываем только категории без company_id (для обратной совместимости)
            $query->whereNull('categories.company_id');
        }
        return $query;
    }

    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
            ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
            ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
            ->whereHas('categoryUsers', function($query) use ($userUuid) {
                $query->where('user_id', $userUuid);
            });

        // Фильтруем по текущей компании пользователя
        $query = $this->addCompanyFilter($query);

        $items = $query->with('users')->paginate($perPage, ['*'], 'page', (int)$page);
        return $items;
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        // Кэшируем справочник категорий на 2 часа
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "categories_all_{$userUuid}_{$companyId}";

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
                ->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query->with('users')->get();
        });
    }

    // Получение только родительских категорий (первого уровня)
    public function getParentCategories($userUuid)
    {
        // Кэшируем справочник родительских категорий на 2 часа
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "categories_parents_{$userUuid}_{$companyId}";

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            $query = Category::leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'users.name as user_name')
                ->whereNull('categories.parent_id') // Только родительские категории
                ->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                })
                ->whereHas('children'); // Только те, у которых есть подкатегории

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query->with('users')->get();
        });
    }

    // Создание
    public function createItem($data)
    {
        $companyId = $this->getCurrentCompanyId();

        $item = new Category();
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->company_id = $companyId;
        $item->save();

        // Создаем связи с пользователями
        foreach ($data['users'] as $userId) {
            CategoryUser::create([
                'category_id' => $item->id,
                'user_id' => $userId
            ]);
        }

        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $companyId = $this->getCurrentCompanyId();

        $item = Category::find($id);
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->company_id = $companyId;
        $item->save();

        // Удаляем старые связи
        CategoryUser::where('category_id', $id)->delete();

        // Создаем новые связи
        foreach ($data['users'] as $userId) {
            CategoryUser::create([
                'category_id' => $id,
                'user_id' => $userId
            ]);
        }

        return true;
    }

    // Удаление
    public function deleteItem($id)
    {
        $item = Category::find($id);
        $item->delete();

        return true;
    }
}
