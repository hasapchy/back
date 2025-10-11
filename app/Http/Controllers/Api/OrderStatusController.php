<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrderStatusRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    protected $repo;

    public function __construct(OrderStatusRepository $repo)
    {
        $this->repo = $repo;
    }

    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $items = $this->repo->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
    }

    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $items = $this->repo->getAllItems($userUuid);

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $request->validate([
            'name' => 'required|string',
            'category_id' => 'required|exists:order_status_categories,id'
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$created) return response()->json(['message' => 'Ошибка создания статуса'], 400);

        // Инвалидируем кэш статусов заказов
        CacheService::invalidateOrderStatusesCache();

        return response()->json(['message' => 'Статус создан']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $request->validate([
            'name' => 'required|string',
            'category_id' => 'required|exists:order_status_categories,id'
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$updated) return response()->json(['message' => 'Ошибка обновления статуса'], 400);

        // Инвалидируем кэш статусов заказов
        CacheService::invalidateOrderStatusesCache();

        return response()->json(['message' => 'Статус обновлен']);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $protectedIds = [1, 2, 3, 4, 5, 6];
        if (in_array($id, $protectedIds)) {
            return response()->json(['message' => 'Системный статус нельзя удалить'], 400);
        }

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) {
            return response()->json(['message' => 'Ошибка удаления статуса'], 400);
        }

        // Инвалидируем кэш статусов заказов
        CacheService::invalidateOrderStatusesCache();

        return response()->json(['message' => 'Статус удален']);
    }
}
