<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseMovementRequest;
use App\Http\Requests\UpdateWarehouseMovementRequest;
use App\Http\Resources\WarehouseMovementResource;
use App\Repositories\WarehouseMovementRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с перемещениями между складами
 */
class WarehouseMovementController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(WarehouseMovementRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список перемещений с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => WarehouseMovementResource::collection($warehouses->items())->resolve(),
            'meta' => [
                'current_page' => $warehouses->currentPage(),
                'next_page' => $warehouses->nextPageUrl(),
                'last_page' => $warehouses->lastPage(),
                'per_page' => $warehouses->perPage(),
                'total' => $warehouses->total(),
            ],
        ]);
    }

    /**
     * Создать перемещение между складами
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseMovementRequest $request)
    {
        $validatedData = $request->validated();

        $accessCheck = $this->checkWarehousesAccess($validatedData['warehouse_from_id'], $validatedData['warehouse_to_id']);
        if ($accessCheck) {
            return $accessCheck;
        }

        $data = [
            'warehouse_from_id' => $validatedData['warehouse_from_id'],
            'warehouse_to_id' => $validatedData['warehouse_to_id'],
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->itemsRepository->createItem($data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка перемещения', 400);
            }

            return $this->successResponse(null, 'Перемещение создано');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка перемещения: '.$th->getMessage(), 400);
        }
    }

    /**
     * Обновить перемещение между складами
     *
     * @param  Request  $request
     * @param  int  $id  ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseMovementRequest $request, $id)
    {
        $validatedData = $request->validated();

        $accessCheck = $this->checkWarehousesAccess($validatedData['warehouse_from_id'], $validatedData['warehouse_to_id']);
        if ($accessCheck) {
            return $accessCheck;
        }

        $data = [
            'warehouse_from_id' => $validatedData['warehouse_from_id'],
            'warehouse_to_id' => $validatedData['warehouse_to_id'],
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->itemsRepository->updateItem($id, $data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка обновления перемещения', 400);
            }

            return $this->successResponse(null, 'Перемещение обновлено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления перемещения: '.$th->getMessage(), 400);
        }
    }

    /**
     * Удалить перемещение между складами
     *
     * @param  int  $id  ID перемещения
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

        $warehouse_deleted = $this->itemsRepository->deleteItem($id);

        if (! $warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления перемещения', 400);
        }

        return $this->successResponse(null, 'Перемещение удалено');
    }

    /**
     * Проверить доступ к двум складам
     *
     * @param  int  $warehouseFromId  ID склада откуда
     * @param  int  $warehouseToId  ID склада куда
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function checkWarehousesAccess(int $warehouseFromId, int $warehouseToId)
    {
        $warehouseFromAccessCheck = $this->checkWarehouseAccess($warehouseFromId);
        if ($warehouseFromAccessCheck) {
            return $warehouseFromAccessCheck;
        }

        return $this->checkWarehouseAccess($warehouseToId);
    }
}
