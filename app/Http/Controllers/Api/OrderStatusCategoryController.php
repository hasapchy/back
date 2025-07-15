<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrderStatusCategoryRepository;
use Illuminate\Http\Request;

class OrderStatusCategoryController extends Controller
{
    protected $repo;

    public function __construct(OrderStatusCategoryRepository $repo)
    {
        $this->repo = $repo;
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

        return response()->json(['message' => 'Категория статусов обновлена']);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) return response()->json(['message' => 'Ошибка удаления'], 400);

        return response()->json(['message' => 'Категория статусов удалена']);
    }
}
