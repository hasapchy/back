<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderStatusRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderStatusResource;
use App\Models\OrderStatus;
use App\Repositories\OrderStatusRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со статусами заказов
 */
class OrderStatusController extends Controller
{
    protected $orderStatusRepository;

    /**
     * Конструктор контроллера
     *
     * @param OrderStatusRepository $orderStatusRepository
     */
    public function __construct(OrderStatusRepository $orderStatusRepository)
    {
        $this->orderStatusRepository = $orderStatusRepository;
    }

    /**
     * Получить список статусов заказов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->orderStatusRepository->getItemsWithPagination($userUuid, 20);

        return OrderStatusResource::collection($items)->response();
    }

    /**
     * Получить все статусы заказов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->orderStatusRepository->getAllItems($userUuid);

        return OrderStatusResource::collection($items)->response();
    }

    /**
     * Создать новый статус заказа
     *
     * @param StoreOrderStatusRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $created = $this->orderStatusRepository->createItem([
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания статуса', 400);

        $status = OrderStatus::with('category')->findOrFail($created->id);
        return $this->dataResponse(new OrderStatusResource($status), 'Статус создан');
    }

    /**
     * Обновить статус заказа
     *
     * @param UpdateOrderStatusRequest $request
     * @param int $id ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateOrderStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $updated = $this->orderStatusRepository->updateItem($id, [
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления статуса', 400);

        $status = OrderStatus::with('category')->findOrFail($id);
        return $this->dataResponse(new OrderStatusResource($status), 'Статус обновлен');
    }

    /**
     * Удалить статус заказа
     *
     * @param int $id ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        if (in_array($id, OrderStatus::getProtectedIds())) {
            return $this->errorResponse('Системный статус нельзя удалить', 400);
        }

        $status = OrderStatus::with('category')->findOrFail($id);
        $deleted = $this->orderStatusRepository->deleteItem($id);
        if (!$deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return $this->dataResponse(new OrderStatusResource($status), 'Статус удален');
    }
}
