<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreSaleRequest;
use App\Repositories\SalesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с продажами
 */
class SaleController extends BaseController
{
    protected $itemRepository;

    /**
     * Конструктор контроллера
     *
     * @param SalesRepository $itemRepository Репозиторий продаж
     */
    public function __construct(SalesRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
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

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $page);

        return $this->paginatedResponse($items);
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
            'user_id'       => $userUuid,
            'client_id'     => $validatedData['client_id'],
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
            $this->itemRepository->createItem($data);

            CacheService::invalidateSalesCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['message' => 'Продажа добавлена'], 201);
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
        $item = $this->itemRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }

        if (!$this->canPerformAction('sales', 'view', $item)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этой продажи');
        }

        return response()->json(['item' => $item]);
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

        $sale = $this->itemRepository->getItemById($id);
        if (!$sale) {
            return $this->notFoundResponse('Продажа не найдена');
        }

        if (!$this->canPerformAction('sales', 'delete', $sale)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этой продажи');
        }

        try {
            $projectId = $sale->project_id ?? null;
            $saleData = [
                'id' => $sale->id,
                'client_id' => $sale->client_id,
                'project_id' => $projectId,
            ];

            $result = $this->itemRepository->deleteItem($id);

            CacheService::invalidateSalesCache();
            CacheService::invalidateClientsCache();
            if ($projectId) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['sale' => $saleData, 'message' => 'Продажа удалена успешно']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка при удалении продажи: ' . $th->getMessage(), 400);
        }
    }
}
