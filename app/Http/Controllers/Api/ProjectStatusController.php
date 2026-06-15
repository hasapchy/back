<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProjectStatusRequest;
use App\Http\Requests\UpdateProjectStatusRequest;
use App\Http\Resources\ProjectStatusReferenceResource;
use App\Http\Resources\ProjectStatusResource;
use App\Models\ProjectStatus;
use App\Repositories\ProjectStatusRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * @group Проекты
 * @subgroup Статусы
 */
class ProjectStatusController extends BaseController
{
    protected $itemsRepository;

    public function __construct(ProjectStatusRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список статусов проектов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                ProjectStatusReferenceResource::class,
                ProjectStatusResource::class,
                $companyId
            ),
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

        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? ProjectStatusReferenceResource::collection($items)
            : ProjectStatusResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать статус проекта
     *
     * @subgroup Управление статусами
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProjectStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'is_visible' => $validatedData['is_visible'] ?? true,
            'kanban_outcome' => $validatedData['kanban_outcome'] ?? null,
            'creator_id' => $userUuid,
        ]);

        CacheService::invalidateProjectsCache();

        return $this->successResponse(null, __('api.statuses.created'));
    }

    /**
     * Обновить статус проекта
     *
     * @subgroup Управление статусами
     *
     * @param  Request  $request
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProjectStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $payload = [
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'is_visible' => $validatedData['is_visible'] ?? true,
        ];
        if (array_key_exists('kanban_outcome', $validatedData)) {
            $payload['kanban_outcome'] = $validatedData['kanban_outcome'];
        }

        $this->itemsRepository->updateItem($id, $payload);

        CacheService::invalidateProjectsCache();

        return $this->successResponse(null, __('api.statuses.updated'));
    }

    /**
     * Удалить статус проекта
     *
     * @subgroup Управление статусами
     *
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $status = ProjectStatus::findOrFail($id);
        if ($status->projects()->count() > 0) {
            return $this->errorResponse(__('Нельзя удалить статус, который используется в проектах'), 400);
        }

        $deleted = $this->itemsRepository->deleteItem($id);
        if (! $deleted) {
            return $this->errorResponse(__('api.statuses.delete_failed'), 400);
        }

        return $this->successResponse(null, __('api.statuses.deleted'));
    }
}
