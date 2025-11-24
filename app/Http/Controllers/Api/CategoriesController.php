<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Repositories\CategoriesRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

class CategoriesController extends Controller
{
    /**
     * @var CategoriesRepository
     */
    protected $itemsRepository;

    /**
     * @param CategoriesRepository $itemsRepository
     */
    public function __construct(CategoriesRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить категории с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, $page);

        return CategoryResource::collection($items)->response();
    }

    /**
     * Получить все категории
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems($userUuid);
        return CategoryResource::collection($items)->response();
    }

    /**
     * Получить родительские категории
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function parents(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getParentCategories($userUuid);
        return CategoryResource::collection($items)->response();
    }

    /**
     * Создать категорию
     *
     * @param StoreCategoryRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCategoryRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $category_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'parent_id' => $request->parent_id,
            'user_id' => $userUuid,
            'users' => $request->users
        ]);

        if (!$category_created) {
            return $this->errorResponse('Ошибка создания категории', 400);
        }

        CacheService::invalidateCategoriesCache();
        $category = Category::with(['parent', 'children', 'user'])->findOrFail($category_created->id);
        return (new CategoryResource($category))->additional([
            'message' => 'Категория создана'
        ])->response();
    }

    /**
     * Обновить категорию
     *
     * @param UpdateCategoryRequest $request
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCategoryRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'parent_id' => $request->parent_id,
            'user_id' => $userUuid,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления категории', 400);
        }

        CacheService::invalidateCategoriesCache();
        $category = Category::with(['parent', 'children', 'user'])->findOrFail($id);
        return (new CategoryResource($category))->additional([
            'message' => 'Категория обновлена'
        ])->response();
    }

    /**
     * Удалить категорию
     *
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $category_deleted = $this->itemsRepository->deleteItem($id);

        if (!$category_deleted) {
            return $this->errorResponse('Ошибка удаления категории', 400);
        }

        CacheService::invalidateCategoriesCache();
        $category = Category::with(['parent', 'children', 'user'])->findOrFail($id);
        return (new CategoryResource($category))->additional([
            'message' => 'Категория удалена'
        ])->response();
    }
}
