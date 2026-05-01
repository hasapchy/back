<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseReceiptRequest;
use App\Http\Requests\UpdateWarehouseReceiptRequest;
use App\Http\Resources\WarehouseReceiptResource;
use App\Repositories\WarehouseReceiptRepository;
use App\Support\NullableInt;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с оприходованиями на склад
 */
class WarehouseReceiptController extends BaseController
{
    public function __construct(
        protected WarehouseReceiptRepository $itemsRepository
    ) {
    }

    /**
     * Получить список оприходований с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $clientId = $request->input('client_id');

        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page, $clientId);

        return $this->successResponse([
            'items' => WarehouseReceiptResource::collection($warehouses->items())->resolve(),
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
     * Получить оприходование по ID
     *
     * @param  int  $id  ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemsRepository->getItemById($id, $userUuid);
        if (! $item) {
            return $this->errorResponse(__('warehouse_receipt.not_found'), 404);
        }

        return $this->successResponse(new WarehouseReceiptResource($item));
    }

    /**
     * Создать оприходование на склад
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseReceiptRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id'] ?? null);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = [
            'client_id' => $validatedData['client_id'],
            'client_balance_id' => NullableInt::fromRequest($validatedData['client_balance_id'] ?? null),
            'warehouse_id' => $validatedData['warehouse_id'],
            'cash_id' => $validatedData['cash_id'] ?? null,
            'creator_id' => $userUuid,
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'project_id' => $validatedData['project_id'] ?? null,
            'is_legacy' => (bool) ($validatedData['is_legacy'] ?? false),
            'is_simple' => (bool) ($validatedData['is_simple'] ?? false),
            'status' => $validatedData['status'] ?? null,
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $receiptId = $this->itemsRepository->createItem($data);

            return $this->successResponse(['id' => $receiptId], __('warehouse_receipt.created_success'));
        } catch (\Throwable $th) {
            return $this->errorResponse(__('warehouse_receipt.store_error', ['message' => $th->getMessage()]), 400);
        }
    }

    /**
     * Обновить оприходование на склад
     *
     * @param  Request  $request
     * @param  int  $id  ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseReceiptRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $receipt = $this->itemsRepository->getItemById($id, $userUuid);
        if (! $receipt) {
            return $this->errorResponse(__('warehouse_receipt.not_found'), 404);
        }

        $data = [
            'client_id' => $receipt->supplier_id,
            'warehouse_id' => $receipt->warehouse_id,
            'cash_id' => $receipt->cash_id,
            'date' => $validatedData['date'] ?? $receipt->date,
            'note' => $validatedData['note'] ?? $receipt->note,
            'products' => $receipt->products,
            'project_id' => $receipt->project_id,
            'status' => array_key_exists('status', $validatedData) ? $validatedData['status'] : null,
        ];

        try {
            $updated = $this->itemsRepository->updateReceipt($id, $data);
            if (! $updated) {
                return $this->errorResponse(__('warehouse_receipt.update_failed'), 400);
            }

            return $this->successResponse(null, __('warehouse_receipt.updated_success'));
        } catch (\Throwable $th) {
            return $this->errorResponse(__('warehouse_receipt.update_error', ['message' => $th->getMessage()]), 400);
        }
    }

    /**
     * Удалить оприходование со склада
     *
     * @param  int  $id  ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $warehouse_deleted = $this->itemsRepository->deleteItem($id);

            if (! $warehouse_deleted) {
                return $this->errorResponse(__('warehouse_receipt.delete_failed'), 400);
            }

            return $this->successResponse(null, __('warehouse_receipt.deleted_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_receipt.not_found'), 404);
        } catch (\Throwable $th) {
            return $this->errorResponse(__('warehouse_receipt.delete_error', ['message' => $th->getMessage()]), 400);
        }
    }
}
