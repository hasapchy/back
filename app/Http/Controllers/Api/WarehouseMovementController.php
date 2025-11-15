<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return $this->paginatedResponse($warehouses);
    }

    /**
     * Создать перемещение между складами
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'warehouse_from_id' => 'required|integer|exists:warehouses,id',
            'warehouse_to_id' => 'required|integer|exists:warehouses,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

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
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'warehouse_from_id' => 'required|integer|exists:warehouses,id',
            'warehouse_to_id' => 'required|integer|exists:warehouses,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

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

        return response()->json(['message' => 'Перемещение удалено']);
    }
}
