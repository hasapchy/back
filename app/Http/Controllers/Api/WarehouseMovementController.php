<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseMovementRequest;
use App\Http\Requests\UpdateWarehouseMovementRequest;
use App\Http\Resources\WarehouseMovementResource;
use App\Repositories\WarehouseMovementRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с перемещениями между складами
 *
 * @group Склады
 * @subgroup Перемещения
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
     * Список перемещений
     *
     * @response 200 {"data":{"items":[],"meta":{"current_page":1,"next_page":null,"last_page":1,"per_page":20,"total":0}}}
     * @response 401 {"error":"Unauthenticated."}
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
     * Создать перемещение
     *
     * @param  Request  $request
     * @response 200 {"data":null,"message":"Перемещение создано"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 422 {"error":"The given data was invalid.","errors":{"warehouse_from_id":["The warehouse from id field is required."]}}
     *
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
                $row = [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
                if (array_key_exists('orig_unit_id', $product)) {
                    $row['orig_unit_id'] = $product['orig_unit_id'];
                }
                if (array_key_exists('orig_quantity', $product)) {
                    $row['orig_quantity'] = $product['orig_quantity'];
                }

                return $row;
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
     * Изменить перемещение
     *
     * @param  Request  $request
     * @param  int  $id  ID перемещения
     * @response 200 {"data":null,"message":"Перемещение обновлено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     * @response 422 {"error":"The given data was invalid.","errors":{"warehouse_from_id":["The warehouse from id field is required."]}}
     *
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
                $row = [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
                if (array_key_exists('orig_unit_id', $product)) {
                    $row['orig_unit_id'] = $product['orig_unit_id'];
                }
                if (array_key_exists('orig_quantity', $product)) {
                    $row['orig_quantity'] = $product['orig_quantity'];
                }

                return $row;
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
     * Удалить перемещение
     *
     * @param  int  $id  ID перемещения
     * @response 200 {"data":null,"message":"Перемещение удалено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     *
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
