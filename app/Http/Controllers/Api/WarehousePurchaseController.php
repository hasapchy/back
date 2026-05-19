<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehousePurchaseRequest;
use App\Http\Requests\StoreWarehousePurchasePaymentRequest;
use App\Http\Requests\UpdateWarehousePurchaseRequest;
use App\Http\Resources\WarehousePurchaseResource;
use App\Repositories\WarehousePurchaseRepository;
use App\Services\WarehouseDocumentPaymentStatusService;
use Illuminate\Http\Request;

/**
 * @group Склады
 * @subgroup Закупки
 */
class WarehousePurchaseController extends BaseController
{
    public function __construct(
        private readonly WarehousePurchaseRepository $itemsRepository
    ) {
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $items = $this->itemsRepository->getItemsWithPagination(
            (int) $request->input('per_page', 20),
            (int) $request->input('page', 1),
            $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null,
            $request->filled('status') ? (string) $request->input('status') : null,
            app(WarehouseDocumentPaymentStatusService::class)->normalizePurchasePaymentStatusFilter(
                $request->filled('payment_status') ? (string) $request->input('payment_status') : null
            ),
        );

        return $this->successResponse([
            'items' => WarehousePurchaseResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id)
    {
        $item = $this->itemsRepository->getItemById($id);
        if (! $item) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        }

        return $this->successResponse(new WarehousePurchaseResource($item));
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehousePurchaseRequest $request)
    {
        try {
            $id = $this->itemsRepository->createItem($request->validated());

            return $this->successResponse(['id' => $id], __('warehouse_purchase.created_success'));
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehousePurchaseRequest $request, int $id)
    {
        try {
            $this->itemsRepository->updateItem($id, $request->validated());

            return $this->successResponse(null, __('warehouse_purchase.updated_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $id)
    {
        try {
            $this->itemsRepository->deleteItem($id);

            return $this->successResponse(null, __('warehouse_purchase.deleted_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function pay(StoreWarehousePurchasePaymentRequest $request, int $id)
    {
        try {
            $txId = $this->itemsRepository->addPayment($id, $request->validated());

            return $this->successResponse(['transaction_id' => $txId], 'Оплата за товар добавлена');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }
}
