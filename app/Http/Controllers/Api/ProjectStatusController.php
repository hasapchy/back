<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectStatusRequest;
use App\Http\Requests\UpdateProjectStatusRequest;
use App\Http\Resources\ProjectStatusResource;
use App\Models\ProjectStatus;
use App\Repositories\ProjectStatusRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со статусами проектов
 */
class ProjectStatusController extends Controller
{
    protected $projectStatusRepository;

    /**
     * Конструктор контроллера
     *
     * @param ProjectStatusRepository $projectStatusRepository
     */
    public function __construct(ProjectStatusRepository $projectStatusRepository)
    {
        $this->projectStatusRepository = $projectStatusRepository;
    }

    /**
     * Получить список статусов проектов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->projectStatusRepository->getItemsWithPagination($userUuid, 20);

        return ProjectStatusResource::collection($items)->response();
    }

    /**
     * Получить все статусы проектов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->projectStatusRepository->getAllItems($userUuid);

        return ProjectStatusResource::collection($items)->response();
    }

    /**
     * Создать новый статус проекта
     *
     * @param StoreProjectStatusRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProjectStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $created = $this->projectStatusRepository->createItem([
            'name' => $request->name,
            'color' => $request->color ?? '#6c757d',
            'user_id' => $userUuid,
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания статуса', 400);

        $status = ProjectStatus::with('user')->findOrFail($created->id);
        return $this->dataResponse(new ProjectStatusResource($status), 'Статус создан');
    }

    /**
     * Обновить статус проекта
     *
     * @param UpdateProjectStatusRequest $request
     * @param int $id ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProjectStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $updated = $this->projectStatusRepository->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color ?? '#6c757d',
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления статуса', 400);

        $status = ProjectStatus::with('user')->findOrFail($id);
        return $this->dataResponse(new ProjectStatusResource($status), 'Статус обновлен');
    }

    /**
     * Удалить статус проекта
     *
     * @param int $id ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $status = ProjectStatus::findOrFail($id);
        if ($status->projects()->count() > 0) {
            return $this->errorResponse('Нельзя удалить статус, который используется в проектах', 400);
        }

        $status = ProjectStatus::with('user')->findOrFail($id);
        $deleted = $this->projectStatusRepository->deleteItem($id);
        if (!$deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return $this->dataResponse(new ProjectStatusResource($status), 'Статус удален');
    }
}
