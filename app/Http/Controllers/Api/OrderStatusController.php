<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return $this->paginatedResponse($items);
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

        return response()->json($items);
    }

    /**
     * Создать новый статус заказа
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'category_id' => 'required|exists:order_status_categories,id'
        ]);

        $created = $this->orderStatusRepository->createItem([
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания статуса', 400);

        return response()->json(['message' => 'Статус создан']);
    }

    /**
     * Обновить статус заказа
     *
     * @param Request $request
     * @param int $id ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'category_id' => 'required|exists:order_status_categories,id'
        ]);

        $updated = $this->orderStatusRepository->updateItem($id, [
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления статуса', 400);

        return response()->json(['message' => 'Статус обновлен']);
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

        $protectedIds = [1, 2, 3, 4, 5, 6];
        if (in_array($id, $protectedIds)) {
            return $this->errorResponse('Системный статус нельзя удалить', 400);
        }

        $deleted = $this->orderStatusRepository->deleteItem($id);
        if (!$deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return response()->json(['message' => 'Статус удален']);
    }
}
