<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Repositories\CategoriesRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

class CategoriesController extends BaseController
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
        $perPage = $request->input('per_page', 20);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => CategoryResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
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
        return $this->successResponse(CategoryResource::collection($items)->resolve());
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
        return $this->successResponse(CategoryResource::collection($items)->resolve());
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
        $validatedData = $request->validated();

        $category_created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'parent_id' => $validatedData['parent_id'] ?? null,
            'creator_id' => $userUuid,
            'users' => $validatedData['users']
        ]);

        if (!$category_created) {
            return $this->errorResponse('Ошибка создания категории', 400);
        }

        CacheService::invalidateCategoriesCache();
        return $this->successResponse(null, 'Категория создана');
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
        $validatedData = $request->validated();

        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $validatedData['name'],
            'parent_id' => $validatedData['parent_id'] ?? null,
            'creator_id' => $userUuid,
            'users' => $validatedData['users']
        ]);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления категории', 400);
        }

        CacheService::invalidateCategoriesCache();
        return $this->successResponse(null, 'Категория обновлена');
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
        return $this->successResponse(null, 'Категория удалена');
    }
}
