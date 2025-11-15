<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrderStatusCategoryRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями статусов заказов
 */
class OrderStatusCategoryController extends Controller
{
    protected $orderStatusCategoryRepository;

    /**
     * Конструктор контроллера
     *
     * @param OrderStatusCategoryRepository $orderStatusCategoryRepository
     */
    public function __construct(OrderStatusCategoryRepository $orderStatusCategoryRepository)
    {
        $this->orderStatusCategoryRepository = $orderStatusCategoryRepository;
    }

    /**
     * Получить список категорий статусов заказов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $items = $this->orderStatusCategoryRepository->getItemsWithPagination($userUuid, $perPage);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все категории статусов заказов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->orderStatusCategoryRepository->getAllItems($userUuid);

        return response()->json($items);
    }

    /**
     * Создать новую категорию статусов заказов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string'
        ]);

        $created = $this->orderStatusCategoryRepository->createItem([
            'name' => $request->name,
            'color' => $request->color ?? null,
            'user_id' => $userUuid
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания категории статусов', 400);

        return response()->json(['message' => 'Категория статусов создана']);
    }

    /**
     * Обновить категорию статусов заказов
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
            'color' => 'nullable|string'
        ]);

        $updated = $this->orderStatusCategoryRepository->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color ?? null,
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления', 400);

        return response()->json(['message' => 'Категория статусов обновлена']);
    }

    /**
     * Удалить категорию статусов заказов
     *
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $deleted = $this->orderStatusCategoryRepository->deleteItem($id);
        if (!$deleted) return $this->errorResponse('Ошибка удаления', 400);

        return response()->json(['message' => 'Категория статусов удалена']);
    }
}
