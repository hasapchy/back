<?php

namespace App\Http\Controllers\Api;

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
class ProjectStatusController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(ProjectStatusRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список статусов проектов с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => ProjectStatusResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Получить все статусы проектов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->itemsRepository->getAllItems($userUuid);

        return $this->successResponse(ProjectStatusResource::collection($items)->resolve());
    }

    /**
     * Создать новый статус проекта
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProjectStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'is_visible' => $validatedData['is_visible'] ?? true,
            'creator_id' => $userUuid,
        ]);
        if (! $created) {
            return $this->errorResponse('Ошибка создания статуса', 400);
        }

        CacheService::invalidateProjectsCache();

        return $this->successResponse(null, 'Статус создан');
    }

    /**
     * Обновить статус проекта
     *
     * @param  Request  $request
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProjectStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $updated = $this->itemsRepository->updateItem($id, [
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'is_visible' => $validatedData['is_visible'] ?? true,
        ]);
        if (! $updated) {
            return $this->errorResponse('Ошибка обновления статуса', 400);
        }

        CacheService::invalidateProjectsCache();

        return $this->successResponse(null, 'Статус обновлен');
    }

    /**
     * Удалить статус проекта
     *
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $status = ProjectStatus::findOrFail($id);
        if ($status->projects()->count() > 0) {
            return $this->errorResponse('Нельзя удалить статус, который используется в проектах', 400);
        }

        $deleted = $this->itemsRepository->deleteItem($id);
        if (! $deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return $this->successResponse(null, 'Статус удален');
    }
}
