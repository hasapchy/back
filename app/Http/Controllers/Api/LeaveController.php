<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Http\Resources\LeaveReferenceResource;
use App\Http\Resources\LeaveResource;
use App\Models\Leave;
use App\Repositories\LeaveRepository;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с записями отпусков
 */
/**
 * @group Кадры
 * @subgroup Отпуска
 */
class LeaveController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(
        LeaveRepository $itemsRepository,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список отпусков
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Leave::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $filters = $this->buildLeaveFilters($request);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $filters, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                LeaveReferenceResource::class,
                LeaveResource::class,
                $companyId
            ),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    /**
     * Получить все записи отпусков
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $filters = $this->buildLeaveFilters($request);

        $items = $this->itemsRepository->getAllItems($userUuid, $filters);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1IndexCollection(
                $items,
                LeaveReferenceResource::class,
                LeaveResource::class,
                $companyId
            )
        );
    }

    /**
     * Получить запись отпуска по ID
     *
     * @param  int  $id  ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $leave = $this->itemsRepository->getItemById($id);
        $this->authorize('view', $leave);

        return $this->successResponse(new LeaveResource($leave));
    }

    /**
     * Создать запись отпуска
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreLeaveRequest $request)
    {
        $this->authorize('create', Leave::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validated = $request->validated();

        $data = [
            'leave_type_id' => $validated['leave_type_id'],
            'user_id' => $validated['user_id'] ?? $userUuid,
            'comment' => $validated['comment'] ?? null,
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
        ];

        $created = $this->itemsRepository->createItem($data);
        if (! $created) {
            return $this->errorResponse(__('Ошибка создания записи отпуска'), 400);
        }

        $companyId = (int) $this->getCurrentCompanyId();
        $leaveUserId = (int) ($data['user_id'] ?? $userUuid);
        if ($companyId > 0 && $leaveUserId > 0) {
            $this->inAppNotificationDispatcher->dispatch(
                $companyId,
                'leaves_new',
                $leaveUserId,
                'Новая заявка на отпуск',
                'С '.substr((string) $data['date_from'], 0, 10).' по '.substr((string) $data['date_to'], 0, 10),
                ['route' => '/leaves/'.$created->id, 'leave_id' => $created->id]
            );
        }

        return $this->successResponse(new LeaveResource($created), __('Запись отпуска создана'));
    }

    /**
     * Обновить запись отпуска
     *
     * @param  int  $id  ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateLeaveRequest $request, $id)
    {
        $leave = Leave::findOrFail($id);
        $this->authorize('update', $leave);

        $validated = $request->validated();

        $data = array_filter([
            'leave_type_id' => $validated['leave_type_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ], fn ($value) => $value !== null);

        $updated = $this->itemsRepository->updateItem($id, $data);
        if (! $updated) {
            return $this->errorResponse(__('api.transfers.update_failed'), 400);
        }

        return $this->successResponse(new LeaveResource($updated), __('Запись отпуска обновлена'));
    }

    /**
     * Удалить запись отпуска
     *
     * @param  int  $id  ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $leave = Leave::findOrFail($id);
        $this->authorize('delete', $leave);

        $deleted = $this->itemsRepository->deleteItem($id);
        if (! $deleted) {
            return $this->errorResponse(__('api.transfers.delete_failed'), 400);
        }

        return $this->successResponse(null, __('Запись отпуска удалена'));
    }

    protected function buildLeaveFilters(Request $request): array
    {
        $filters = [];

        if ($request->has('user_id')) {
            $filters['user_id'] = $request->input('user_id');
        }
        if ($request->has('leave_type_id')) {
            $filters['leave_type_id'] = $request->input('leave_type_id');
        }
        if ($request->has('date_from')) {
            $filters['date_from'] = $request->input('date_from');
        }
        if ($request->has('date_to')) {
            $filters['date_to'] = $request->input('date_to');
        }

        $filters['active_only'] = $request->boolean('active_only', true);

        return $filters;
    }
}
