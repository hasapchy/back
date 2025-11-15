<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

/**
 * Контроллер для работы со складами
 */
class WarehouseController extends Controller
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseRepository $warehouseRepository
     */
    public function __construct(WarehouseRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список складов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $warehouses = $this->warehouseRepository->getWarehousesWithPagination($userUuid, 20, $page);

        return $this->paginatedResponse($warehouses);
    }

    /**
     * Получить все склады
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->warehouseRepository->getAllItems($userUuid);

        return response()->json($warehouses);
    }

    /**
     * Создать новый склад
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        $warehouse_created = $this->warehouseRepository->createItem($request->name, $request->users);

        if (!$warehouse_created) {
            return $this->errorResponse('Ошибка создания склада', 400);
        }

        return response()->json(['warehouse' => $warehouse_created, 'message' => 'Склад создан']);
    }

    /**
     * Обновить склад
     *
     * @param Request $request
     * @param int $id ID склада
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $warehouse = \App\Models\Warehouse::findOrFail($id);

        if (!$this->canPerformAction('warehouses', 'update', $warehouse)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого склада');
        }

        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        $warehouse_updated = $this->warehouseRepository->updateItem($id, $request->name, $request->users);

        if (!$warehouse_updated) {
            return $this->errorResponse('Ошибка обновления склада', 400);
        }

        return response()->json(['warehouse' => $warehouse_updated, 'message' => 'Склад обновлен']);
    }

    /**
     * Удалить склад
     *
     * @param int $id ID склада
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $warehouse = \App\Models\Warehouse::findOrFail($id);

        if (!$this->canPerformAction('warehouses', 'delete', $warehouse)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого склада');
        }

        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления склада', 400);
        }

        return response()->json(['message' => 'Склад удален']);
    }
}
