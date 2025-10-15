<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrderStatusCategoryRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class OrderStatusCategoryController extends Controller
{
    protected $repo;

    public function __construct(OrderStatusCategoryRepository $repo)
    {
        $this->repo = $repo;
    }

    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $perPage = $request->input('per_page', 20);
        $items = $this->repo->getItemsWithPagination($userUuid, $perPage);

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
            'color' => 'nullable|string'
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'color' => $request->color ?? null,
            'user_id' => $userUuid
        ]);
        if (!$created) return response()->json(['message' => 'Ошибка создания категории статусов'], 400);

        // Инвалидируем кэш категорий статусов заказов
        CacheService::invalidateOrderStatusCategoriesCache();

        return response()->json(['message' => 'Категория статусов создана']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string'
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color ?? null,
        ]);
        if (!$updated) return response()->json(['message' => 'Ошибка обновления'], 400);

        // Инвалидируем кэш категорий статусов заказов
        CacheService::invalidateOrderStatusCategoriesCache();

        return response()->json(['message' => 'Категория статусов обновлена']);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) return response()->json(['message' => 'Ошибка удаления'], 400);

        // Инвалидируем кэш категорий статусов заказов
        CacheService::invalidateOrderStatusCategoriesCache();

        return response()->json(['message' => 'Категория статусов удалена']);
    }
}
