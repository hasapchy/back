<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\LeaveRepository;
use App\Models\Leave;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с записями отпусков
 */
class LeaveController extends BaseController
{
    protected $leaveRepository;

    /**
     * Конструктор контроллера
     *
     * @param LeaveRepository $leaveRepository
     */
    public function __construct(LeaveRepository $leaveRepository)
    {
        $this->leaveRepository = $leaveRepository;
    }

    /**
     * Получить список записей отпусков с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        
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

        $items = $this->leaveRepository->getItemsWithPagination($userUuid, $perPage, $filters);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все записи отпусков
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

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

        $items = $this->leaveRepository->getAllItems($userUuid, $filters);

        return response()->json($items);
    }

    /**
     * Получить запись отпуска по ID
     *
     * @param int $id ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $leave = $this->leaveRepository->getItemById($id);
            return response()->json(['item' => $leave]);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Запись отпуска не найдена');
        }
    }

    /**
     * Создать новую запись отпуска
     *
     * @param Request $request
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
            'date_to' => 'required|date|after_or_equal:date_from'
        ]);

        $data = [
            'leave_type_id' => $request->leave_type_id,
            'user_id' => $request->user_id ?? $userUuid,
            'comment' => $request->comment ?? null,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to
        ];


        $created = $this->leaveRepository->createItem($data);
        if (!$created) return $this->errorResponse('Ошибка создания записи отпуска', 400);

        return response()->json(['item' => $created, 'message' => 'Запись отпуска создана']);
    }

    /**
     * Обновить запись отпуска
     *
     * @param Request $request
     * @param int $id ID записи
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
            'date_to' => 'nullable|date'
        ];

        // Если оба поля дат присутствуют, проверяем что date_to >= date_from
        if ($request->has('date_from') && $request->has('date_to')) {
            $validationRules['date_to'] .= '|after_or_equal:date_from';
        }

        $request->validate($validationRules);

        try {
            $leave = Leave::findOrFail($id);

            $data = [];
            if ($request->has('leave_type_id') && $request->leave_type_id !== null) {
                $data['leave_type_id'] = $request->leave_type_id;
            }
            if ($request->has('user_id') && $request->user_id !== null) {
                $data['user_id'] = $request->user_id;
            }
            if ($request->has('comment')) {
                $data['comment'] = $request->comment;
            }
            if ($request->has('date_from') && $request->date_from !== null) {
                $data['date_from'] = $request->date_from;
            }
            if ($request->has('date_to') && $request->date_to !== null) {
                $data['date_to'] = $request->date_to;
            }

            $updated = $this->leaveRepository->updateItem($id, $data);
            if (!$updated) return $this->errorResponse('Ошибка обновления', 400);

            return response()->json(['item' => $updated, 'message' => 'Запись отпуска обновлена']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Запись отпуска не найдена');
        }
    }

    /**
     * Удалить запись отпуска
     *
     * @param int $id ID записи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $leave = Leave::findOrFail($id);

            $deleted = $this->leaveRepository->deleteItem($id);
            if (!$deleted) return $this->errorResponse('Ошибка удаления', 400);

            return response()->json(['message' => 'Запись отпуска удалена']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Запись отпуска не найдена');
        }
    }
}

