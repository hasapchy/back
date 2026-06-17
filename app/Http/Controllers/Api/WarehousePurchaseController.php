<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehousePurchaseRequest;
use App\Http\Requests\StoreWarehousePurchasePaymentRequest;
use App\Http\Requests\UpdateWarehousePurchaseRequest;
use App\Http\Resources\WarehousePurchaseResource;
use App\Models\WhPurchase;
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
        $this->authorize('viewAny', WhPurchase::class);

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
            'meta' => $this->paginationMeta($items),
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
        $this->authorize('view', $item);

        return $this->successResponse(new WarehousePurchaseResource($item));
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehousePurchaseRequest $request)
    {
        $this->authorize('create', WhPurchase::class);

        try {
            $id = $this->itemsRepository->createItem($request->validated());
            $item = $this->itemsRepository->getItemById($id);
            if (! $item) {
                return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
            }

            return $this->successResponse(new WarehousePurchaseResource($item), __('warehouse_purchase.created_success'));
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehousePurchaseRequest $request, int $id)
    {
        $existing = $this->itemsRepository->getItemById($id);
        if (! $existing) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        }
        $this->authorize('update', $existing);

        try {
            $this->itemsRepository->updateItem($id, $request->validated());
            $item = $this->itemsRepository->getItemById($id);
            if (! $item) {
                return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
            }

            return $this->successResponse(new WarehousePurchaseResource($item), __('warehouse_purchase.updated_success'));
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
        $existing = $this->itemsRepository->getItemById($id);
        if (! $existing) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        }
        $this->authorize('delete', $existing);

        try {
            $this->itemsRepository->deleteItem($id);

            return $this->successResponse(null, __('warehouse_purchase.deleted_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function pay(StoreWarehousePurchasePaymentRequest $request, int $id)
    {
        $existing = $this->itemsRepository->getItemById($id);
        if (! $existing) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        }
        $this->authorize('update', $existing);

        try {
            $txId = $this->itemsRepository->addPayment($id, $request->validated());

            return $this->successResponse(['transaction_id' => $txId], __('api.warehouse_purchase.goods_payment_added'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_purchase.not_found'), 404);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }
}
