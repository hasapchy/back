<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseWriteoffRequest;
use App\Http\Requests\UpdateWarehouseWriteoffRequest;
use App\Repositories\WarehouseWriteoffRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со списаниями со склада
 */
class WarehouseWriteoffController extends BaseController
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(WarehouseWriteoffRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список списаний с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->paginatedResponse($warehouses);
    }

    /**
     * Создать списание со склада
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseWriteoffRequest $request)
    {
        $validatedData = $request->validated();

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'warehouse_id' => $validatedData['warehouse_id'],
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка списания', 400);
            }

            return response()->json(['message' => 'Списание создано']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания: '.$th->getMessage(), 400);
        }
    }

    /**
     * Обновить списание со склада
     *
     * @param  Request  $request
     * @param  int  $id  ID списания
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseWriteoffRequest $request, $id)
    {
        $validatedData = $request->validated();

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'warehouse_id' => $validatedData['warehouse_id'],
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->warehouseRepository->updateItem($id, $data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка списания', 400);
            }

            return response()->json(['message' => 'Списание обновлено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания: '.$th->getMessage(), 400);
        }
    }

    /**
     * Удалить списание со склада
     *
     * @param  int  $id  ID списания
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $writeoff = \App\Models\WhWriteoff::findOrFail($id);

        if ($writeoff->warehouse_id) {
            $warehouseAccessCheck = $this->checkWarehouseAccess($writeoff->warehouse_id);
            if ($warehouseAccessCheck) {
                return $warehouseAccessCheck;
            }
        }

        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (! $warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления списания', 400);
        }

        return response()->json(['message' => 'Списание удалено']);
    }
}
