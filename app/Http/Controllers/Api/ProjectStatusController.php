<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreProjectStatusRequest;
use App\Http\Requests\UpdateProjectStatusRequest;
use App\Models\ProjectStatus;
use App\Repositories\ProjectStatusRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со статусами проектов
 */
class ProjectStatusController extends BaseController
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

        $perPage = $request->input('per_page', 20);

        $items = $this->projectStatusRepository->getItemsWithPagination($userUuid, $perPage);

        return $this->paginatedResponse($items);
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

        return response()->json($items);
    }

    /**
     * Создать новый статус проекта
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProjectStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->projectStatusRepository->createItem([
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'is_tr_visible' => $validatedData['is_tr_visible'] ?? true,
            'user_id' => $userUuid,
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания статуса', 400);

        CacheService::invalidateProjectsCache();

        return response()->json(['message' => 'Статус создан']);
    }

    /**
     * Обновить статус проекта
     *
     * @param Request $request
     * @param int $id ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProjectStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $updated = $this->projectStatusRepository->updateItem($id, [
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'is_tr_visible' => $validatedData['is_tr_visible'] ?? true,
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления статуса', 400);

        CacheService::invalidateProjectsCache();

        return response()->json(['message' => 'Статус обновлен']);
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

        $deleted = $this->projectStatusRepository->deleteItem($id);
        if (!$deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return response()->json(['message' => 'Статус удален']);
    }
}
