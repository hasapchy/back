<?php

namespace App\Http\Controllers\Api;

use App\Enums\WhWriteoffReason;
use App\Http\Requests\StoreWarehouseWriteoffRequest;
use App\Http\Requests\UpdateWarehouseWriteoffRequest;
use App\Http\Resources\WarehouseWriteoffResource;
use App\Repositories\WarehouseWriteoffRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со списаниями со склада
 *
 * @group Склады
 * @subgroup Списания
 */
class WarehouseWriteoffController extends BaseController
{
    protected $itemsRepository;

    /**
     * Преобразовать техническую ошибку в понятный текст
     */
    private function resolveWriteoffErrorMessage(\Throwable $th): string
    {
        return match ($th->getMessage()) {
            'INSUFFICIENT_STOCK' => 'Недостаточно остатка на складе',
            default => 'Ошибка списания: '.$th->getMessage(),
        };
    }

    /**
     * Конструктор контроллера
     */
    public function __construct(WarehouseWriteoffRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список списаний
     *
     * Query: `reason` — только указанная причина; `exclude_reason` — все кроме указанной (игнорируется, если задан `reason`).
     *
     * @param  Request  $request
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
        $reasonRaw = $request->query('reason');
        $reason = is_string($reasonRaw) && $reasonRaw !== '' && WhWriteoffReason::tryFrom($reasonRaw) !== null
            ? $reasonRaw
            : null;

        $excludeReasonRaw = $request->query('exclude_reason');
        $excludeReason = $reason === null
            && is_string($excludeReasonRaw) && $excludeReasonRaw !== '' && WhWriteoffReason::tryFrom($excludeReasonRaw) !== null
            ? $excludeReasonRaw
            : null;

        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, (int) $perPage, (int) $page, $reason, $excludeReason);

        return $this->successResponse([
            'items' => WarehouseWriteoffResource::collection($warehouses->items())->resolve(),
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
     * Списание по ID
     *
     * @param  int  $id  ID списания
     * @response 200 {"data":{"id":1}}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Списание не найдено"}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemsRepository->getItemByIdForUser((int) $id, $userUuid);

        if (! $item) {
            return $this->errorResponse(__('api.writeoff.not_found'), 404);
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($item['warehouse_id'] ?? null);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        return $this->successResponse($item);
    }

    /**
     * Создать списание
     *
     * @param  Request  $request
     * @response 200 {"data":null,"message":"Списание создано"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 422 {"error":"The given data was invalid.","errors":{"warehouse_id":["The warehouse id field is required."]}}
     *
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
            'reason' => $validatedData['reason'],
            'source_receipt_id' => $validatedData['source_receipt_id'] ?? null,
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                $row = [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'source_receipt_product_id' => $product['source_receipt_product_id'] ?? null,
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
                return $this->errorResponse(__('api.writeoff.operation_failed'), 400);
            }

            return $this->successResponse(null, __('api.writeoff.created'));
        } catch (\Throwable $th) {
            return $this->errorResponse($this->resolveWriteoffErrorMessage($th), 400);
        }
    }

    /**
     * Изменить списание
     *
     * @param  Request  $request
     * @param  int  $id  ID списания
     * @response 200 {"data":null,"message":"Списание обновлено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Списание не найдено"}
     * @response 422 {"error":"The given data was invalid.","errors":{"warehouse_id":["The warehouse id field is required."]}}
     *
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
            'reason' => $validatedData['reason'],
            'source_receipt_id' => $validatedData['source_receipt_id'] ?? null,
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                $row = [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'source_receipt_product_id' => $product['source_receipt_product_id'] ?? null,
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
                return $this->errorResponse(__('api.writeoff.operation_failed'), 400);
            }

            return $this->successResponse(null, __('api.writeoff.updated'));
        } catch (\Throwable $th) {
            return $this->errorResponse($this->resolveWriteoffErrorMessage($th), 400);
        }
    }

    /**
     * Удалить списание
     *
     * @param  int  $id  ID списания
     * @response 200 {"data":null,"message":"Списание удалено"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     *
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

        $warehouse_deleted = $this->itemsRepository->deleteItem($id);

        if (! $warehouse_deleted) {
            return $this->errorResponse(__('api.writeoff.delete_failed'), 400);
        }

        return $this->successResponse(null, __('api.writeoff.deleted'));
    }
}
