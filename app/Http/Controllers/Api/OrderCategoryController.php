<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrderCategoryRepository;
use Illuminate\Http\Request;

class OrderCategoryController extends Controller
{
    protected $repo;

    public function __construct(OrderCategoryRepository $repo)
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
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'user_id' => $userUuid,
        ]);

        if (!$created) return response()->json(['message' => 'Ошибка создания категории заказа'], 400);
        return response()->json(['message' => 'Категория заказа создана']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $request->validate([
            'name' => 'required|string',
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'user_id' => $userUuid,
        ]);

        if (!$updated) return response()->json(['message' => 'Ошибка обновления категории заказа'], 400);
        return response()->json(['message' => 'Категория заказа обновлена']);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) return response()->json(['message' => 'Ошибка удаления категории заказа'], 400);

        return response()->json(['message' => 'Категория заказа удалена']);
    }
}
