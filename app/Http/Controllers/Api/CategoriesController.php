<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return $this->paginatedResponse($items);
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
        return response()->json($items);
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
        return response()->json($items);
    }

    /**
     * Создать категорию
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'parent_id' => 'nullable|exists:categories,id',
            'users' => 'required|array|min:1',
            'users.*' => 'exists:users,id'
        ]);

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
        return response()->json(['message' => 'Категория создана']);
    }

    /**
     * Обновить категорию
     *
     * @param Request $request
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'parent_id' => 'nullable|exists:categories,id',
            'users' => 'required|array|min:1',
            'users.*' => 'exists:users,id'
        ]);

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
        return response()->json(['message' => 'Категория обновлена']);
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
        return response()->json(['message' => 'Категория удалена']);
    }
}
