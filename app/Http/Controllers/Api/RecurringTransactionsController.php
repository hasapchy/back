<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreRecurringTransactionRequest;
use App\Http\Requests\UpdateRecurringTransactionRequest;
use App\Http\Resources\RecScheduleResource;
use App\Models\RecSchedule;
use App\Repositories\RecSchedulesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * @group Финансы
 * @subgroup Регулярные транзакции
 */
class RecurringTransactionsController extends BaseController
{
    public function __construct(
        private RecSchedulesRepository $repository
    ) {
    }

    /**
     * Список расписаний
     *
     * @param Request $request
     * @response 200 {"data":{"items":[],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":0}}}
     * @response 401 {"error":"Unauthenticated."}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', RecSchedule::class);

        $userId = $this->getAuthenticatedUserIdOrFail();
        $viewAll = $this->requireAuthenticatedUser()->can('rec_schedules_view_all');
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

        return $this->successResponse([
            'items' => RecScheduleResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Расписание по ID
     *
     * @param int $id
     * @response 200 {"data":{"id":1}}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Расписание не найдено"}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id)
    {
        $this->getAuthenticatedUserIdOrFail();
        $schedule = $this->repository->getItemById($id);

        if (! $schedule) {
            return $this->errorResponse(__('Расписание не найдено'), 404);
        }

        $this->authorize('view', $schedule);

        return $this->successResponse(new RecScheduleResource($schedule));
    }

    /**
     * Создать расписание
     *
     * @param StoreRecurringTransactionRequest $request
     * @response 200 {"data":{"id":1},"message":"Расписание создано"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 422 {"error":"The given data was invalid.","errors":{"template_id":["The template id field is required."]}}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreRecurringTransactionRequest $request)
    {
        $this->authorize('create', RecSchedule::class);

        $userId = $this->getAuthenticatedUserIdOrFail();
        $data = $request->validated();
        $data['creator_id'] = $userId;
        $data['company_id'] = $this->getCurrentCompanyId();

        $schedule = $this->repository->createItem($data);

        CacheService::invalidateTransactionsCache();

        return $this->successResponse(new RecScheduleResource($schedule), __('Расписание создано'));
    }

    /**
     * Изменить расписание
     *
     * @param UpdateRecurringTransactionRequest $request
     * @param int $id
     * @response 200 {"data":{"id":1},"message":"Расписание обновлено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Расписание не найдено"}
     * @response 422 {"error":"The given data was invalid.","errors":{"template_id":["The template id field is required."]}}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateRecurringTransactionRequest $request, int $id)
    {
        $schedule = $this->repository->getItemById($id);

        if (! $schedule) {
            return $this->errorResponse(__('Расписание не найдено'), 404);
        }

        $this->authorize('update', $schedule);

        $data = $request->validated();
        $this->repository->updateItem($id, $data);
        $schedule->refresh();

        CacheService::invalidateTransactionsCache();

        return $this->successResponse(new RecScheduleResource($schedule), __('Расписание обновлено'));
    }

    /**
     * Удалить расписание
     *
     * @param int $id
     * @response 200 {"data":null,"message":"Расписание удалено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Расписание не найдено"}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $id)
    {
        $schedule = $this->repository->getItemById($id);

        if (! $schedule) {
            return $this->errorResponse(__('Расписание не найдено'), 404);
        }

        $this->authorize('delete', $schedule);

        $this->repository->deleteItem($schedule);
        CacheService::invalidateTransactionsCache();

        return $this->successResponse(null, __('Расписание удалено'));
    }
}
