<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreRecurringTransactionRequest;
use App\Http\Requests\UpdateRecurringTransactionRequest;
use App\Models\RecSchedule;
use App\Repositories\RecSchedulesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class RecurringTransactionsController extends BaseController
{
    public function __construct(
        private RecSchedulesRepository $repository
    ) {
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $viewAll = $this->hasPermission('rec_schedules_view_all');
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $templateId = $request->input('template_id') !== null ? (int) $request->input('template_id') : null;

        $items = $this->repository->getItemsWithPagination(
            $perPage,
            $page,
            $userId,
            $viewAll,
            $this->getCurrentCompanyId(),
            $templateId
        );

        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
        ]);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $schedule = $this->repository->getItemById($id);

        if (!$schedule) {
            return $this->notFoundResponse('Расписание не найдено');
        }

        if (!$this->canView($schedule, $userId)) {
            return $this->forbiddenResponse('Нет прав на просмотр этого расписания');
        }

        return response()->json(['item' => $schedule]);
    }

    /**
     * @param StoreRecurringTransactionRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreRecurringTransactionRequest $request)
    {
        if (!$this->hasPermission('rec_schedules_create')) {
            return $this->forbiddenResponse('Нет прав на создание повторяющихся транзакций');
        }

        $userId = $this->getAuthenticatedUserIdOrFail();
        $data = $request->validated();
        $data['creator_id'] = $userId;
        $data['company_id'] = $this->getCurrentCompanyId();

        $schedule = $this->repository->createItem($data);

        CacheService::invalidateTransactionsCache();

        return response()->json(['item' => $schedule, 'message' => 'Расписание создано']);
    }

    /**
     * @param UpdateRecurringTransactionRequest $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateRecurringTransactionRequest $request, int $id)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $schedule = $this->repository->getItemById($id);

        if (!$schedule) {
            return $this->notFoundResponse('Расписание не найдено');
        }

        if (!$this->canUpdate($schedule, $userId)) {
            return $this->forbiddenResponse('Нет прав на редактирование этого расписания');
        }

        $data = $request->validated();
        $this->repository->updateItem($id, $data);
        $schedule->refresh();

        CacheService::invalidateTransactionsCache();

        return response()->json(['item' => $schedule, 'message' => 'Расписание обновлено']);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $id)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $schedule = $this->repository->getItemById($id);

        if (!$schedule) {
            return $this->notFoundResponse('Расписание не найдено');
        }

        if (!$this->canDelete($schedule, $userId)) {
            return $this->forbiddenResponse('Нет прав на удаление этого расписания');
        }

        $this->repository->deleteItem($schedule);
        CacheService::invalidateTransactionsCache();

        return response()->json(['message' => 'Расписание удалено']);
    }

    /**
     * @param RecSchedule $schedule
     * @param int $userId
     * @return bool
     */
    private function canView(RecSchedule $schedule, int $userId): bool
    {
        if ($this->hasPermission('rec_schedules_view_all')) {
            return true;
        }
        return $schedule->creator_id === $userId;
    }

    /**
     * @param RecSchedule $schedule
     * @param int $userId
     * @return bool
     */
    private function canUpdate(RecSchedule $schedule, int $userId): bool
    {
        if ($this->hasPermission('rec_schedules_update_all')) {
            return true;
        }
        return $this->hasPermission('rec_schedules_update') && $schedule->creator_id === $userId;
    }

    /**
     * @param RecSchedule $schedule
     * @param int $userId
     * @return bool
     */
    private function canDelete(RecSchedule $schedule, int $userId): bool
    {
        if ($this->hasPermission('rec_schedules_delete_all')) {
            return true;
        }
        return $this->hasPermission('rec_schedules_delete') && $schedule->creator_id === $userId;
    }
}
