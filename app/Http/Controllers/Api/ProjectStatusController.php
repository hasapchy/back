<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectStatus;
use App\Repositories\ProjectStatusRepository;
use App\Services\CacheService;
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
            'color' => 'nullable|string|max:7'
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'color' => $request->color ?? '#6c757d',
            'user_id' => $userUuid,
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания статуса', 400);

        CacheService::invalidateProjectStatusesCache();

        return response()->json(['message' => 'Статус создан']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string|max:7'
        ]);

        $updated = $this->repo->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color ?? '#6c757d',
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления статуса', 400);

        CacheService::invalidateProjectStatusesCache();

        return response()->json(['message' => 'Статус обновлен']);
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $status = ProjectStatus::findOrFail($id);
        if ($status->projects()->count() > 0) {
            return $this->errorResponse('Нельзя удалить статус, который используется в проектах', 400);
        }

        $deleted = $this->repo->deleteItem($id);
        if (!$deleted) {
                return $this->errorResponse('Ошибка удаления статуса', 400);
            }

        CacheService::invalidateProjectStatusesCache();

        return response()->json(['message' => 'Статус удален']);
    }
}
