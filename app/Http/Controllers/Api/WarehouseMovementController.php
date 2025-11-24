<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWarehouseMovementRequest;
use App\Http\Requests\UpdateWarehouseMovementRequest;
use App\Http\Resources\WarehouseMovementResource;
use App\Models\WhMovement;
use App\Repositories\WarehouseMovementRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с перемещениями между складами
 */
class WarehouseMovementController extends Controller
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseMovementRepository $warehouseRepository
     */
    public function __construct(WarehouseMovementRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список перемещений с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

        return WarehouseMovementResource::collection($warehouses)->response();
    }

    /**
     * Создать перемещение между складами
     *
     * @param StoreWarehouseMovementRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseMovementRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouseFromAccessCheck = $this->checkWarehouseAccess($request->warehouse_from_id);
        if ($warehouseFromAccessCheck) {
            return $warehouseFromAccessCheck;
        }

        $warehouseToAccessCheck = $this->checkWarehouseAccess($request->warehouse_to_id);
        if ($warehouseToAccessCheck) {
            return $warehouseToAccessCheck;
        }

        $data = array(
            'warehouse_from_id' => $request->warehouse_from_id,
            'warehouse_to_id' => $request->warehouse_to_id,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $request->products)
        );

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка перемещения', 400);
            }

            $movement = WhMovement::with(['warehouseFrom', 'warehouseTo', 'user'])->findOrFail($warehouse_created->id);
            return $this->dataResponse(new WarehouseMovementResource($movement), 'Перемещение создано');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка перемещения' . $th->getMessage(), 400);
        }
    }

    /**
     * Обновить перемещение между складами
     *
     * @param UpdateWarehouseMovementRequest $request
     * @param int $id ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseMovementRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouseFromAccessCheck = $this->checkWarehouseAccess($request->warehouse_from_id);
        if ($warehouseFromAccessCheck) {
            return $warehouseFromAccessCheck;
        }

        $warehouseToAccessCheck = $this->checkWarehouseAccess($request->warehouse_to_id);
        if ($warehouseToAccessCheck) {
            return $warehouseToAccessCheck;
        }

        $data = array(
            'warehouse_from_id' => $request->warehouse_from_id,
            'warehouse_to_id' => $request->warehouse_to_id,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $request->products)
        );

        try {
            $warehouse_created = $this->warehouseRepository->updateItem($id, $data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка обновления перемещения', 400);
            }

            $movement = WhMovement::with(['warehouseFrom', 'warehouseTo', 'user'])->findOrFail($id);
            return $this->dataResponse(new WarehouseMovementResource($movement), 'Перемещение обновлено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления перемещения' . $th->getMessage(), 400);
        }
    }

    /**
     * Удалить перемещение между складами
     *
     * @param int $id ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $movement = \App\Models\WhMovement::findOrFail($id);

        if ($movement->wh_from) {
            $warehouseFrom = \App\Models\Warehouse::findOrFail($movement->wh_from);
            if (!$this->canPerformAction('warehouses', 'view', $warehouseFrom)) {
                return $this->forbiddenResponse('У вас нет прав на склад-отправитель');
            }
        }

        if ($movement->wh_to) {
            $warehouseTo = \App\Models\Warehouse::findOrFail($movement->wh_to);
            if (!$this->canPerformAction('warehouses', 'view', $warehouseTo)) {
                return $this->forbiddenResponse('У вас нет прав на склад-получатель');
            }
        }

        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления перемещения', 400);
        }

        return $this->dataResponse(new WarehouseMovementResource($movement), 'Перемещение удалено');
    }
}
