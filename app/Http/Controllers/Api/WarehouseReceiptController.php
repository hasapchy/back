<?php

namespace App\Http\Controllers\Api;

use App\Enums\WhReceiptStatus;
use App\Http\Requests\StoreWarehouseReceiptRequest;
use App\Http\Requests\UpdateWarehouseReceiptRequest;
use App\Http\Resources\WarehouseReceiptResource;
use App\Exceptions\WarehouseLockedForInventoryException;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\WarehouseDocumentPaymentStatusService;
use App\Support\NullableInt;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с оприходованиями на склад
 *
 * @group Склады
 * @subgroup Приходы
 */
class WarehouseReceiptController extends BaseController
{
    public function __construct(
        protected WarehouseReceiptRepository $itemsRepository
    ) {
    }

    /**
     * Список оприходований
     *
     * @response 200 {"data":{"items":[],"meta":{"current_page":1,"next_page":null,"last_page":1,"per_page":20,"total":0}}}
     * @response 401 {"error":"Unauthenticated."}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $status = WhReceiptStatus::tryFrom((string) ($request->input('status') ?? ''))?->value;
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;
        $search = $search !== '' ? $search : null;

        $warehouses = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            (int) $request->input('per_page', 20),
            (int) $request->input('page', 1),
            NullableInt::positiveOrNull($request->input('client_id')),
            $status,
            NullableInt::positiveOrNull($request->input('warehouse_id')),
            NullableInt::positiveOrNull($request->input('product_id')),
            is_string($dateFilter) && $dateFilter !== '' ? $dateFilter : 'all_time',
            is_string($startDate) && $startDate !== '' ? $startDate : null,
            is_string($endDate) && $endDate !== '' ? $endDate : null,
            app(WarehouseDocumentPaymentStatusService::class)->normalizeReceiptPaymentStatusFilter(
                $request->filled('payment_status') ? (string) $request->input('payment_status') : null
            ),
            $search,
            filter_var($request->input('eligible_for_return'), FILTER_VALIDATE_BOOLEAN),
        );

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
     * Оприходование по ID
     *
     * @param  int  $id  ID оприходования
     * @response 200 {"data":{"id":1}}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Оприходование не найдено"}
     *
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
     * Создать оприходование
     *
     * @param  Request  $request
     * @response 200 {"data":{"id":1},"message":"Оприходование создано"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 422 {"error":"The given data was invalid.","errors":{"warehouse_id":["The warehouse id field is required."]}}
     *
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
            'purchase_id' => isset($validatedData['purchase_id']) ? (int) $validatedData['purchase_id'] : null,
            'cash_id' => $validatedData['cash_id'] ?? null,
            'creator_id' => $userUuid,
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'status' => $validatedData['status'] ?? null,
            'products' => array_map(function ($product) {
                $row = [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price'],
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
            $receiptId = $this->itemsRepository->createItem($data);
            $receipt = $this->itemsRepository->getItemById($receiptId, $userUuid);
            if (! $receipt) {
                return $this->errorResponse(__('warehouse_receipt.not_found'), 404);
            }

            return $this->successResponse(new WarehouseReceiptResource($receipt), __('warehouse_receipt.created_success'));
        } catch (WarehouseLockedForInventoryException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $th) {
            return $this->errorResponse(__('warehouse_receipt.store_error', ['message' => $th->getMessage()]), 400);
        }
    }

    /**
     * Изменить оприходование
     *
     * @param  Request  $request
     * @param  int  $id  ID оприходования
     * @response 200 {"data":null,"message":"Оприходование обновлено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Оприходование не найдено"}
     * @response 422 {"error":"The given data was invalid.","errors":{"status":["The selected status is invalid."]}}
     *
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

        if ($receipt->status === WhReceiptStatus::Completed) {
            return $this->errorResponse(__('warehouse_receipt.receipt_completed_readonly'), 400);
        }

        if (isset($validatedData['status'])) {
            $targetStatus = WhReceiptStatus::tryFrom((string) $validatedData['status']);

            if ($targetStatus === WhReceiptStatus::Completed) {
                return $this->errorResponse(__('warehouse_receipt.completion_is_automatic'), 400);
            }
        }

        if (! empty($validatedData['products'])) {
            $products = array_map(function (array $product) {
                $row = [
                    'product_id' => (int) $product['product_id'],
                    'quantity' => (float) $product['quantity'],
                    'price' => (float) $product['price'],
                ];
                if (array_key_exists('orig_unit_id', $product)) {
                    $row['orig_unit_id'] = $product['orig_unit_id'];
                }
                if (array_key_exists('orig_quantity', $product)) {
                    $row['orig_quantity'] = $product['orig_quantity'];
                }

                return $row;
            }, $validatedData['products']);
        } else {
            $products = array_map(static function ($product) {
                $documentPrice = $product->orig_unit_price !== null
                    ? (float) $product->orig_unit_price
                    : (float) $product->price;

                return [
                    'product_id' => (int) $product->product_id,
                    'quantity' => (float) $product->quantity,
                    'price' => $documentPrice,
                    'orig_unit_id' => $product->orig_unit_id !== null ? (int) $product->orig_unit_id : null,
                    'orig_quantity' => $product->orig_quantity !== null ? (float) $product->orig_quantity : null,
                ];
            }, $receipt->products->all());
        }

        $data = [
            'client_id' => $receipt->supplier_id,
            'warehouse_id' => $receipt->warehouse_id,
            'cash_id' => $receipt->cash_id,
            'date' => $validatedData['date'] ?? $receipt->date,
            'note' => $validatedData['note'] ?? $receipt->note,
            'products' => $products,
            'status' => array_key_exists('status', $validatedData) ? $validatedData['status'] : null,
        ];

        try {
            $updated = $this->itemsRepository->updateReceipt($id, $data);
            if (! $updated) {
                return $this->errorResponse(__('warehouse_receipt.update_failed'), 400);
            }

            $receipt = $this->itemsRepository->getItemById((int) $id, $userUuid);
            if (! $receipt) {
                return $this->errorResponse(__('warehouse_receipt.not_found'), 404);
            }

            return $this->successResponse(new WarehouseReceiptResource($receipt), __('warehouse_receipt.updated_success'));
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * Удалить оприходование
     *
     * @param  int  $id  ID оприходования
     * @response 200 {"data":null,"message":"Оприходование удалено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Оприходование не найдено"}
     *
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
