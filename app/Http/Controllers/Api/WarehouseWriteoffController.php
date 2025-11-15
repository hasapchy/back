<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseWriteoffRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со списаниями со склада
 */
class WarehouseWriteoffController extends Controller
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseWriteoffRepository $warehouseRepository
     */
    public function __construct(WarehouseWriteoffRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список списаний с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

        return $this->paginatedResponse($warehouses);
    }

    /**
     * Создать списание со склада
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = array(
            'warehouse_id' => $request->warehouse_id,
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
                return $this->errorResponse('Ошибка списания', 400);
            }

            return response()->json(['message' => 'Списание создано']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания' . $th->getMessage(), 400);
        }
    }

    /**
     * Обновить списание со склада
     *
     * @param Request $request
     * @param int $id ID списания
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = array(
            'warehouse_id' => $request->warehouse_id,
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
                return $this->errorResponse('Ошибка списания', 400);
            }

            return response()->json(['message' => 'Списание обновлено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания' . $th->getMessage(), 400);
        }
    }

    /**
     * Удалить списание со склада
     *
     * @param int $id ID списания
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $writeoff = \App\Models\WhWriteoff::findOrFail($id);

        if ($writeoff->warehouse_id) {
            $warehouse = \App\Models\Warehouse::findOrFail($writeoff->warehouse_id);
            if (!$this->canPerformAction('warehouses', 'view', $warehouse)) {
                return $this->forbiddenResponse('У вас нет прав на этот склад');
            }
        }

        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления списания', 400);
        }

        return response()->json(['message' => 'Списание удалено']);
    }
}
