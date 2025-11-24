<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Repositories\SalesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с продажами
 */
class SaleController extends Controller
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
        $per_page = $request->input('per_page', 10);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $page);

        return SaleResource::collection($items)->response();
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

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'user_id'       => $userUuid,
            'client_id'     => $request->client_id,
            'project_id'    => $request->project_id,
            'type'          => $request->type,
            'cash_id'       => $request->cash_id,
            'warehouse_id'  => $request->warehouse_id,
            'currency_id'   => $request->currency_id,
            'discount'      => $request->discount  ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'date'          => $request->date      ?? now(),
            'note'          => $request->note      ?? '',
            'products'      => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
            ], $request->products),
        ];

        try {
            $sale = $this->itemRepository->createItem($data);
            $sale = Sale::with(['client', 'user', 'project', 'cash', 'warehouse'])->findOrFail($sale->id);

            CacheService::invalidateSalesCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return $this->dataResponse(new SaleResource($sale), 'Продажа добавлена', 201);
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
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }

        if (!$this->canPerformAction('sales', 'view', $item)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этой продажи');
        }

        $sale = Sale::with(['client', 'user', 'project', 'cash', 'warehouse'])->findOrFail($id);
        return $this->dataResponse(new SaleResource($sale));
    }

    /**
     * Удалить продажу
     *
     * @param int $id ID продажи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

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

            $sale = Sale::with(['client', 'user', 'project', 'cash', 'warehouse'])->findOrFail($id);
            return $this->dataResponse(new SaleResource($sale), 'Продажа удалена успешно');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка при удалении продажи: ' . $th->getMessage(), 400);
        }
    }
}
