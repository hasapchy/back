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
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $items = $this->repo->getItemsWithPagination($userUuid, $perPage);

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
            'color' => 'nullable|string'
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'color' => $request->color ?? null,
            'user_id' => $userUuid
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания категории статусов', 400);

        CacheService::invalidateOrderStatusCategoriesCache();

        return response()->json(['message' => 'Категория статусов создана']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string'
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color ?? null,
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления', 400);

        CacheService::invalidateOrderStatusCategoriesCache();

        return response()->json(['message' => 'Категория статусов обновлена']);
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) return $this->errorResponse('Ошибка удаления', 400);

        CacheService::invalidateOrderStatusCategoriesCache();

        return response()->json(['message' => 'Категория статусов удалена']);
    }
}
