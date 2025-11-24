<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
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

        return WarehouseResource::collection($warehouses)->response();
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

        return WarehouseResource::collection($warehouses)->response();
    }

    /**
     * Создать новый склад
     *
     * @param StoreWarehouseRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseRequest $request)
    {

        $warehouse_created = $this->warehouseRepository->createItem($request->name, $request->users);

        if (!$warehouse_created) {
            return $this->errorResponse('Ошибка создания склада', 400);
        }

        $warehouse = Warehouse::with('users')->findOrFail($warehouse_created->id);
        return (new WarehouseResource($warehouse))->additional([
            'message' => 'Склад создан'
        ])->response();
    }

    /**
     * Обновить склад
     *
     * @param UpdateWarehouseRequest $request
     * @param int $id ID склада
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseRequest $request, $id)
    {
        $warehouse = \App\Models\Warehouse::findOrFail($id);

        if (!$this->canPerformAction('warehouses', 'update', $warehouse)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого склада');
        }

        $warehouse_updated = $this->warehouseRepository->updateItem($id, $request->name, $request->users);

        if (!$warehouse_updated) {
            return $this->errorResponse('Ошибка обновления склада', 400);
        }

        $warehouse = Warehouse::with('users')->findOrFail($id);
        return (new WarehouseResource($warehouse))->additional([
            'message' => 'Склад обновлен'
        ])->response();
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

        return (new WarehouseResource($warehouse))->additional([
            'message' => 'Склад удален'
        ])->response();
    }
}
