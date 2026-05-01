<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseWaybillRequest;
use App\Http\Requests\UpdateWarehouseWaybillRequest;
use App\Http\Resources\WhWaybillResource;
use App\Repositories\WarehouseWaybillRepository;
use Illuminate\Http\Request;

class WarehouseReceiptWaybillController extends BaseController
{
    public function __construct(
        private readonly WarehouseWaybillRepository $waybillRepository
    ) {
    }

    /**
     * @param  string  $code
     * @return string
     */
    private function mapWaybillRuntimeMessage(string $code): string
    {
        return match ($code) {
            'WAYBILL_PRODUCT_NOT_IN_RECEIPT' => (string) __('warehouse_waybill.product_not_in_receipt'),
            'WAYBILL_QUANTITY_EXCEEDS_RECEIPT_LINE' => (string) __('warehouse_waybill.quantity_exceeds_receipt'),
            'WAYBILLS_NOT_ALLOWED_FOR_LEGACY_RECEIPT' => (string) __('warehouse_waybill.not_allowed_legacy'),
            'WAYBILL_READONLY_FOR_SIMPLE_RECEIPT' => (string) __('warehouse_waybill.readonly_simple_receipt'),
            default => $code,
        };
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(int $receiptId)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $items = $this->waybillRepository->listForReceipt($receiptId, $userUuid);

        return $this->successResponse(WhWaybillResource::collection($items)->resolve());
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function allowedProductLines(Request $request, int $receiptId)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validated = $request->validate([
            'editing_waybill_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $editingWaybillId = isset($validated['editing_waybill_id'])
            ? (int) $validated['editing_waybill_id']
            : null;

        $lines = $this->waybillRepository->allowedWaybillProductLines($receiptId, $userUuid, $editingWaybillId);

        return $this->successResponse(['lines' => $lines]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseWaybillRequest $request, int $receiptId)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $waybill = $this->waybillRepository->createForReceipt($receiptId, $userUuid, $request->validated());

            return $this->successResponse(new WhWaybillResource($waybill), __('warehouse_waybill.created_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_receipt.not_found'), 404);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($this->mapWaybillRuntimeMessage($e->getMessage()), 400);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseWaybillRequest $request, int $receiptId, int $waybillId)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $waybill = $this->waybillRepository->updateWaybill($receiptId, $waybillId, $userUuid, $request->validated());

            return $this->successResponse(new WhWaybillResource($waybill), __('warehouse_waybill.updated_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_waybill.not_found'), 404);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($this->mapWaybillRuntimeMessage($e->getMessage()), 400);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $receiptId, int $waybillId)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $this->waybillRepository->deleteWaybill($receiptId, $waybillId, $userUuid);

            return $this->successResponse(null, __('warehouse_waybill.deleted_success'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('warehouse_waybill.not_found'), 404);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($this->mapWaybillRuntimeMessage($e->getMessage()), 400);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
