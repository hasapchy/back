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
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->repo->getItemsWithPagination($userUuid, 20);

        return $this->paginatedResponse($items);
    }

    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->repo->getAllItems($userUuid);

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'category_id' => 'required|exists:order_status_categories,id'
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания статуса', 400);

        CacheService::invalidateOrderStatusesCache();

        return response()->json(['message' => 'Статус создан']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'category_id' => 'required|exists:order_status_categories,id'
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'category_id' => $request->category_id,
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления статуса', 400);

        CacheService::invalidateOrderStatusesCache();

        return response()->json(['message' => 'Статус обновлен']);
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $protectedIds = [1, 2, 3, 4, 5, 6];
        if (in_array($id, $protectedIds)) {
            return $this->errorResponse('Системный статус нельзя удалить', 400);
        }

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) {
                return $this->errorResponse('Ошибка удаления статуса', 400);
            }

        CacheService::invalidateOrderStatusesCache();

        return response()->json(['message' => 'Статус удален']);
    }
}
