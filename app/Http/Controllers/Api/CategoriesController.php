<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryReferenceResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Repositories\CategoriesRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

/**
 * @group Каталог
 * @subgroup Категории
 */
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
     * Список категорий
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Category::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                CategoryReferenceResource::class,
                CategoryResource::class,
                $companyId
            ),
            'meta' => $this->paginationMeta($items),
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
        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? CategoryReferenceResource::collection($items)
            : CategoryResource::collection($items);

        return $this->successResponse($collection->resolve());
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
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->useReferenceContractsForWave1IndexShow($companyId)
                ? CategoryReferenceResource::collection($items)->resolve()
                : CategoryResource::collection($items)->resolve()
        );
    }

    /**
     * Создать категорию
     *
     * @param StoreCategoryRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCategoryRequest $request)
    {
        $this->authorize('create', Category::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $category_created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'parent_id' => $validatedData['parent_id'] ?? null,
            'creator_id' => $userUuid,
            'users' => $validatedData['users']
        ]);

        if (!$category_created) {
            return $this->errorResponse(__('Ошибка создания категории'), 400);
        }

        CacheService::invalidateCategoriesCache();
        return $this->successResponse(null, __('Категория создана'));
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
        $this->authorize('update', Category::findOrFail($id));

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $validatedData['name'],
            'parent_id' => $validatedData['parent_id'] ?? null,
            'creator_id' => $userUuid,
            'users' => $validatedData['users']
        ]);

        if (!$category_updated) {
            return $this->errorResponse(__('Ошибка обновления категории'), 400);
        }

        CacheService::invalidateCategoriesCache();
        return $this->successResponse(null, __('Категория обновлена'));
    }

    /**
     * Удалить категорию
     *
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->authorize('delete', Category::findOrFail($id));

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $category_deleted = $this->itemsRepository->deleteItem($id);

        if (!$category_deleted) {
            return $this->errorResponse(__('Ошибка удаления категории'), 400);
        }

        CacheService::invalidateCategoriesCache();
        return $this->successResponse(null, __('Категория удалена'));
    }
}
