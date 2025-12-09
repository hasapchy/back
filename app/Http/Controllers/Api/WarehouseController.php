<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Repositories\WarehouseRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

/**
 * Контроллер для работы со складами
 */
class WarehouseController extends BaseController
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
        $perPage = $request->input('per_page', 20);
        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, $perPage, $page);

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
    public function store(StoreWarehouseRequest $request)
    {
        $validatedData = $request->validated();

        $warehouse_created = $this->warehouseRepository->createItem($validatedData['name'], $validatedData['users']);

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
    public function update(UpdateWarehouseRequest $request, $id)
    {
        $warehouse = \App\Models\Warehouse::findOrFail($id);

        if (!$this->canPerformAction('warehouses', 'update', $warehouse)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого склада');
        }

        $validatedData = $request->validated();

        $warehouse_updated = $this->warehouseRepository->updateItem($id, $validatedData['name'], $validatedData['users']);

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
