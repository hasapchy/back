<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreOrderStatusCategoryRequest;
use App\Http\Requests\UpdateOrderStatusCategoryRequest;
use App\Repositories\OrderStatusCategoryRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями статусов заказов
 */
class OrderStatusCategoryController extends BaseController
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
     * @param StoreOrderStatusCategoryRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderStatusCategoryRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->orderStatusCategoryRepository->createItem([
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'user_id' => $userUuid
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания категории статусов', 400);

        return response()->json(['message' => 'Категория статусов создана']);
    }

    /**
     * Обновить категорию статусов заказов
     *
     * @param UpdateOrderStatusCategoryRequest $request
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateOrderStatusCategoryRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $updateData = ['name' => $validatedData['name']];
        if (isset($validatedData['color'])) {
            $updateData['color'] = $validatedData['color'];
        }

        $this->orderStatusCategoryRepository->updateItem($id, $updateData);

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
