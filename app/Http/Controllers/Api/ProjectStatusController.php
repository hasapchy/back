<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectStatusRepository;
use Illuminate\Http\Request;

class ProjectStatusController extends Controller
{
    protected $repo;

    public function __construct(ProjectStatusRepository $repo)
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
            'color' => 'nullable|string|max:7'
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'color' => $request->color ?? '#6c757d',
            'user_id' => $userUuid,
        ]);
        if (!$created) return response()->json(['message' => 'Ошибка создания статуса'], 400);

        return response()->json(['message' => 'Статус создан']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) return response()->json(['message' => 'Unauthorized'], 401);

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string|max:7'
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color ?? '#6c757d',
        ]);
        if (!$updated) return response()->json(['message' => 'Ошибка обновления статуса'], 400);

        return response()->json(['message' => 'Статус обновлен']);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Проверяем, есть ли проекты с этим статусом
        $status = \App\Models\ProjectStatus::findOrFail($id);
        if ($status->projects()->count() > 0) {
            return response()->json(['message' => 'Нельзя удалить статус, который используется в проектах'], 400);
        }

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) {
            return response()->json(['message' => 'Ошибка удаления статуса'], 400);
        }

        return response()->json(['message' => 'Статус удален']);
    }
}
