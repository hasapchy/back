<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrderStatusRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderStatusReferenceResource;
use App\Http\Resources\OrderStatusResource;
use App\Models\Order;
use App\Repositories\OrderStatusRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со статусами заказов
 */
/**
 * @group Заказы
 * @subgroup Статусы заказов
 */
class OrderStatusController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(OrderStatusRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список статусов заказов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                OrderStatusReferenceResource::class,
                OrderStatusResource::class,
                $companyId
            ),
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
     * Получить все статусы заказов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->itemsRepository->getAllItems($userUuid);

        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? OrderStatusReferenceResource::collection($items)
            : OrderStatusResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать статус заказа
     *
     * @subgroup Управление статусами заказов
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'category_id' => $validatedData['category_id'],
            'is_active' => $validatedData['is_active'] ?? true,
        ]);
        if (! $created) {
            return $this->errorResponse('Ошибка создания статуса', 400);
        }

        return $this->successResponse(null, 'Статус создан');
    }

    /**
     * Обновить статус заказа
     *
     * @subgroup Управление статусами заказов
     *
     * @param  Request  $request
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateOrderStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $protectedIds = [1, 5, 6];
        if (in_array($id, $protectedIds) && isset($validatedData['is_active']) && ! $validatedData['is_active']) {
            return $this->errorResponse('Этот статус нельзя отключить', 400);
        }

        if (isset($validatedData['is_active']) && ! $validatedData['is_active']) {
            $ordersCount = Order::where('status_id', $id)->count();
            if ($ordersCount > 0) {
                return $this->errorResponse("Нельзя отключить статус, на котором есть заказы ({$ordersCount} шт.)", 400);
            }
        }

        $updateData = [
            'name' => $validatedData['name'],
            'category_id' => $validatedData['category_id'],
        ];

        if (isset($validatedData['is_active'])) {
            $updateData['is_active'] = $validatedData['is_active'];
        }

        $updated = $this->itemsRepository->updateItem($id, $updateData);
        if (! $updated) {
            return $this->errorResponse('Ошибка обновления статуса', 400);
        }

        return $this->successResponse(null, 'Статус обновлен');
    }

    /**
     * Удалить статус заказа
     *
     * @subgroup Управление статусами заказов
     *
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $protectedIds = [1, 2, 4, 5, 6];
        if (in_array($id, $protectedIds)) {
            return $this->errorResponse('Системный статус нельзя удалить', 400);
        }

        $deleted = $this->itemsRepository->deleteItem($id);
        if (! $deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return $this->successResponse(null, 'Статус удален');
    }
}
