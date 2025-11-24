<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWarehouseReceiptRequest;
use App\Http\Requests\UpdateWarehouseReceiptRequest;
use App\Http\Resources\WarehouseReceiptResource;
use App\Models\WhReceipt;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с оприходованиями на склад
 */
class WarehouseReceiptController extends Controller
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseReceiptRepository $warehouseRepository
     */
    public function __construct(WarehouseReceiptRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список оприходований с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

        return WarehouseReceiptResource::collection($warehouses)->response();
    }

    /**
     * Получить оприходование по ID
     *
     * @param int $id ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->warehouseRepository->getItemById($id, $userUuid);
        if (!$item) {
            return $this->notFoundResponse('Оприходование не найдено');
        }

        $receipt = WhReceipt::with(['warehouse', 'user', 'client', 'cash', 'project'])->findOrFail($id);
        return $this->dataResponse(new WarehouseReceiptResource($receipt));
    }

    /**
     * Создать оприходование на склад
     *
     * @param StoreWarehouseReceiptRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseReceiptRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = array(
            'client_id' => $request->client_id,
            'warehouse_id' => $request->warehouse_id,
            'type'        => $request->type,
            'cash_id'     => $request->cash_id,
            'user_id' => $userUuid,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'project_id' => $request->project_id ?? null,
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price']
                ];
            }, $request->products)
        );

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка оприходования', 400);
            }

            $receipt = WhReceipt::with(['warehouse', 'user', 'client', 'cash', 'project'])
                ->where('user_id', $userUuid)
                ->where('warehouse_id', $request->warehouse_id)
                ->latest()
                ->firstOrFail();
            return $this->dataResponse(new WarehouseReceiptResource($receipt), 'Оприходование создано');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка оприходования: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Обновить оприходование на склад
     *
     * @param UpdateWarehouseReceiptRequest $request
     * @param int $id ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseReceiptRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $receipt = $this->warehouseRepository->getItemById($id, $userUuid);
        if (!$receipt) {
            return $this->notFoundResponse('Оприходование не найдено');
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = [
            'client_id' => $request->client_id,
            'warehouse_id' => $request->warehouse_id,
            'cash_id' => $request->cash_id,
            'date' => $request->date,
            'note' => $request->note,
            'products' => $request->products,
            'project_id' => $request->project_id ?? null,
        ];

        try {
            $updated = $this->warehouseRepository->updateReceipt($id, $data);
            if (!$updated) {
                return $this->errorResponse('Ошибка обновления приходования', 400);
            }

            $receipt = WhReceipt::with(['warehouse', 'user', 'client', 'cash', 'project'])->findOrFail($id);
            return $this->dataResponse(new WarehouseReceiptResource($receipt), 'Приходование обновлено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления приходования: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Удалить оприходование со склада
     *
     * @param int $id ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

            if (!$warehouse_deleted) {
                return $this->errorResponse('Ошибка удаления оприходования', 400);
            }

            $receipt = WhReceipt::with(['warehouse', 'user', 'client', 'cash', 'project'])->findOrFail($id);
            return $this->dataResponse(new WarehouseReceiptResource($receipt), 'Оприходование удалено');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Оприходование не найдено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка удаления оприходования: ' . $th->getMessage(), 400);
        }
    }
}
