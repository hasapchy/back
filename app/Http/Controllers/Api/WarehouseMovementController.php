<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreWarehouseMovementRequest;
use App\Http\Requests\UpdateWarehouseMovementRequest;
use App\Repositories\WarehouseMovementRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с перемещениями между складами
 */
class WarehouseMovementController extends BaseController
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

        $perPage = $request->input('per_page', 20);

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, $perPage);

        return $this->paginatedResponse($warehouses);
    }

    /**
     * Создать перемещение между складами
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseMovementRequest $request)
    {
        $validatedData = $request->validated();

        $warehouseFromAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_from_id']);
        if ($warehouseFromAccessCheck) {
            return $warehouseFromAccessCheck;
        }

        $warehouseToAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_to_id']);
        if ($warehouseToAccessCheck) {
            return $warehouseToAccessCheck;
        }

        $data = array(
            'warehouse_from_id' => $validatedData['warehouse_from_id'],
            'warehouse_to_id' => $validatedData['warehouse_to_id'],
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $validatedData['products'])
        );

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка перемещения', 400);
            }

            return response()->json(['message' => 'Перемещение создано']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка перемещения' . $th->getMessage(), 400);
        }
    }

    /**
     * Обновить перемещение между складами
     *
     * @param Request $request
     * @param int $id ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseMovementRequest $request, $id)
    {
        $validatedData = $request->validated();

        $warehouseFromAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_from_id']);
        if ($warehouseFromAccessCheck) {
            return $warehouseFromAccessCheck;
        }

        $warehouseToAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_to_id']);
        if ($warehouseToAccessCheck) {
            return $warehouseToAccessCheck;
        }

        $data = array(
            'warehouse_from_id' => $validatedData['warehouse_from_id'],
            'warehouse_to_id' => $validatedData['warehouse_to_id'],
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $validatedData['products'])
        );

        try {
            $warehouse_created = $this->warehouseRepository->updateItem($id, $data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка обновления перемещения', 400);
            }

            return response()->json(['message' => 'Перемещение обновлено']);
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
            $warehouseFromAccessCheck = $this->checkWarehouseAccess($movement->wh_from);
            if ($warehouseFromAccessCheck) {
                return $warehouseFromAccessCheck;
            }
        }

        if ($movement->wh_to) {
            $warehouseToAccessCheck = $this->checkWarehouseAccess($movement->wh_to);
            if ($warehouseToAccessCheck) {
                return $warehouseToAccessCheck;
            }
        }

        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления перемещения', 400);
        }

        return response()->json(['message' => 'Перемещение удалено']);
    }
}
