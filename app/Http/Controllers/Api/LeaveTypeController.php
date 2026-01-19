<?php

namespace App\Http\Controllers\Api;

use App\Repositories\LeaveTypeRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с типами отпусков
 */
class LeaveTypeController extends BaseController
{
    protected $leaveTypeRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(LeaveTypeRepository $leaveTypeRepository)
    {
        $this->leaveTypeRepository = $leaveTypeRepository;
    }

    /**
     * Получить список типов отпусков с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $items = $this->leaveTypeRepository->getItemsWithPagination($perPage, $page);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все типы отпусков
     *
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string|max:7',
        ]);

        $created = $this->leaveTypeRepository->createItem([
            'name' => $request->name,
            'color' => $request->color,
        ]);
        if (! $created) {
            return $this->errorResponse('Ошибка создания типа отпуска', 400);
        }

        return response()->json(['item' => $created, 'message' => 'Тип отпуска создан']);
    }

    /**
     * Обновить тип отпуска
     *
     * @param  int  $id  ID типа отпуска
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string|max:7',
        ]);

        $updated = $this->leaveTypeRepository->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color,
        ]);
        if (! $updated) {
            return $this->errorResponse('Ошибка обновления', 400);
        }

        return response()->json(['item' => $updated, 'message' => 'Тип отпуска обновлен']);
    }

    /**
     * Удалить тип отпуска
     *
     * @param  int  $id  ID типа отпуска
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $deleted = $this->leaveTypeRepository->deleteItem($id);
            if (! $deleted) {
                return $this->errorResponse('Ошибка удаления', 400);
            }

            return response()->json(['message' => 'Тип отпуска удален']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
