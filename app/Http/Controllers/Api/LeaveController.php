<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeaveResource;
use App\Models\Leave;
use App\Repositories\LeaveRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с записями отпусков
 */
class LeaveController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(LeaveRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список записей отпусков с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $filters = $this->buildLeaveFilters($request);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $filters, $page);

        return $this->successResponse([
            'items' => LeaveResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
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

        return $this->successResponse(LeaveResource::collection($items)->resolve());
    }

    /**
     * Получить запись отпуска по ID
     *
     * @param  int  $id  ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $leave = $this->itemsRepository->getItemById($id);

            return $this->successResponse(new LeaveResource($leave));
        } catch (\Exception $e) {
            return $this->errorResponse('Запись отпуска не найдена', 404);
        }
    }

    /**
     * Создать новую запись отпуска
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'comment' => 'nullable|string',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $data = [
            'leave_type_id' => $request->leave_type_id,
            'user_id' => $request->user_id ?? $userUuid,
            'comment' => $request->comment ?? null,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
        ];

        $created = $this->itemsRepository->createItem($data);
        if (! $created) {
            return $this->errorResponse('Ошибка создания записи отпуска', 400);
        }

        return $this->successResponse(new LeaveResource($created), 'Запись отпуска создана');
    }

    /**
     * Обновить запись отпуска
     *
     * @param  int  $id  ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validationRules = [
            'leave_type_id' => 'nullable|integer|exists:leave_types,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'comment' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ];

        // Если оба поля дат присутствуют, проверяем что date_to >= date_from
        if ($request->has('date_from') && $request->has('date_to')) {
            $validationRules['date_to'] .= '|after_or_equal:date_from';
        }

        $request->validate($validationRules);

        try {
            $leave = Leave::findOrFail($id);

            $data = array_filter([
                'leave_type_id' => $request->input('leave_type_id'),
                'user_id' => $request->input('user_id'),
                'comment' => $request->input('comment'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ], fn ($value) => $value !== null);

            $updated = $this->itemsRepository->updateItem($id, $data);
            if (! $updated) {
                return $this->errorResponse('Ошибка обновления', 400);
            }

            return $this->successResponse(new LeaveResource($updated), 'Запись отпуска обновлена');
        } catch (\Exception $e) {
            return $this->errorResponse('Запись отпуска не найдена', 404);
        }
    }

    /**
     * Удалить запись отпуска
     *
     * @param  int  $id  ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $leave = Leave::findOrFail($id);

            $deleted = $this->itemsRepository->deleteItem($id);
            if (! $deleted) {
                return $this->errorResponse('Ошибка удаления', 400);
            }

            return $this->successResponse(null, 'Запись отпуска удалена');
        } catch (\Exception $e) {
            return $this->errorResponse('Запись отпуска не найдена', 404);
        }
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

        return $filters;
    }
}
