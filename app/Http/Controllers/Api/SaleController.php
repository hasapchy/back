<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Repositories\SalesRepository;
use App\Services\CacheService;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use App\Support\NullableInt;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с продажами
 */
class SaleController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param SalesRepository $itemsRepository Репозиторий продаж
     */
    public function __construct(
        SalesRepository $itemsRepository,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список продаж с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 20);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $clientId = $request->input('client_id');

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $page, $clientId);

        return $this->successResponse([
            'items' => SaleResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Создать новую продажу
     *
     * @param StoreSaleRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreSaleRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id'] ?? null);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'creator_id'       => $userUuid,
            'client_id'     => $validatedData['client_id'],
            'client_balance_id' => NullableInt::fromRequest($validatedData['client_balance_id'] ?? null),
            'project_id'    => $validatedData['project_id'] ?? null,
            'type'          => $validatedData['type'],
            'cash_id'       => $validatedData['cash_id'] ?? null,
            'warehouse_id'  => $validatedData['warehouse_id'],
            'currency_id'   => $validatedData['currency_id'] ?? null,
            'discount'      => $validatedData['discount'] ?? 0,
            'discount_type' => $validatedData['discount_type'] ?? 'percent',
            'date'          => $validatedData['date'] ?? now(),
            'note'          => $validatedData['note'] ?? '',
            'products'      => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
            ], $validatedData['products']),
        ];

        try {
            $sale = $this->itemsRepository->createItem($data);

            CacheService::invalidateSalesCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            $companyId = (int) $this->getCurrentCompanyId();
            $this->inAppNotificationDispatcher->dispatch(
                $companyId,
                'sales_new',
                $userUuid,
                'Новая продажа',
                'Продажа #'.$sale->id,
                ['route' => '/sales/'.$sale->id, 'sale_id' => $sale->id]
            );

            return $this->successResponse(null, 'Продажа добавлена', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Обновить продажу
     *
     * @param UpdateSaleRequest $request
     * @param int $id ID продажи
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateSaleRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $sale = $this->itemsRepository->getItemById($id);
        if (!$sale) {
            return $this->errorResponse('Продажа не найдена', 404);
        }

        $this->authorize('update', $sale);

        $validatedData = $request->validated();

        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id'] ?? null);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'creator_id'       => $userUuid,
            'client_id'     => $validatedData['client_id'],
            'client_balance_id' => NullableInt::fromRequest($validatedData['client_balance_id'] ?? null),
            'project_id'    => $validatedData['project_id'] ?? null,
            'type'          => $validatedData['type'],
            'cash_id'       => $validatedData['cash_id'] ?? null,
            'warehouse_id'  => $validatedData['warehouse_id'],
            'currency_id'   => $validatedData['currency_id'] ?? null,
            'discount'      => $validatedData['discount'] ?? 0,
            'discount_type' => $validatedData['discount_type'] ?? 'percent',
            'date'          => $validatedData['date'] ?? now(),
            'note'          => $validatedData['note'] ?? '',
            'products'      => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
            ], $validatedData['products']),
        ];

        try {
            $this->itemsRepository->updateItem((int) $id, $data);

            CacheService::invalidateSalesCache();
            CacheService::invalidateClientsCache();
            if (!empty($validatedData['project_id'])) {
                CacheService::invalidateProjectsCache();
            }

            return $this->successResponse(null, 'Продажа обновлена');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Получить продажу по ID
     *
     * @param int $id ID продажи
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $item = $this->itemsRepository->getItemById($id);
        if (!$item) {
            return $this->errorResponse('Not found', 404);
        }

        $this->authorize('view', $item);

        return $this->successResponse(new SaleResource($item));
    }

    /**
     * Удалить продажу
     *
     * @param int $id ID продажи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $sale = $this->itemsRepository->getItemById($id);
        if (!$sale) {
            return $this->errorResponse('Продажа не найдена', 404);
        }

        $this->authorize('delete', $sale);

        try {
            $projectId = $sale->project_id ?? null;
            $saleData = [
                'id' => $sale->id,
                'client_id' => $sale->client_id,
                'project_id' => $projectId,
            ];

            $result = $this->itemsRepository->deleteItem($id);

            CacheService::invalidateSalesCache();
            CacheService::invalidateClientsCache();
            if ($projectId) {
                CacheService::invalidateProjectsCache();
            }

            return $this->successResponse($saleData, 'Продажа удалена успешно');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка при удалении продажи: ' . $th->getMessage(), 400);
        }
    }
}
