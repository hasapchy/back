<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\LeaveTypeRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с типами отпусков
 */
class LeaveTypeController extends Controller
{
    protected $leaveTypeRepository;

    /**
     * Конструктор контроллера
     *
     * @param LeaveTypeRepository $leaveTypeRepository
     */
    public function __construct(LeaveTypeRepository $leaveTypeRepository)
    {
        $this->leaveTypeRepository = $leaveTypeRepository;
    }

    /**
     * Получить список типов отпусков с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {  
        $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $items = $this->leaveTypeRepository->getItemsWithPagination($perPage);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все типы отпусков
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $items = $this->leaveTypeRepository->getAllItems();

        return response()->json($items);
    }

    /**
     * Создать новый тип отпуска
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string'
        ]);

        $created = $this->leaveTypeRepository->createItem([
            'name' => $request->name
        ]);
        if (!$created) return $this->errorResponse('Ошибка создания типа отпуска', 400);

        return response()->json(['message' => 'Тип отпуска создан']);
    }

    /**
     * Обновить тип отпуска
     *
     * @param Request $request
     * @param int $id ID типа отпуска
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string'
        ]);

        $updated = $this->leaveTypeRepository->updateItem($id, [
            'name' => $request->name
        ]);
        if (!$updated) return $this->errorResponse('Ошибка обновления', 400);

        return response()->json(['message' => 'Тип отпуска обновлен']);
    }

    /**
     * Удалить тип отпуска
     *
     * @param int $id ID типа отпуска
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $deleted = $this->leaveTypeRepository->deleteItem($id);
            if (!$deleted) return $this->errorResponse('Ошибка удаления', 400);

            return response()->json(['message' => 'Тип отпуска удален']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
