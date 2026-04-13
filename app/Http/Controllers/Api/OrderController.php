<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Repositories\OrdersRepository;
use App\Services\CacheService;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Events\OrderFirstStageCountUpdated;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use App\Exports\GenericExport;
use App\Http\Resources\OrderResource;
use App\Support\NullableInt;
use App\Support\SimpleUser;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Контроллер для работы с заказами
 */
class OrderController extends BaseController
{

    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param OrdersRepository $itemsRepository Репозиторий заказов
     */
    public function __construct(
        OrdersRepository $itemsRepository,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список заказов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $this->authorize('viewAny', Order::class);

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 20);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $statusFilter = $request->input('status_id');
        $projectFilter = $request->input('project_id');
        $clientFilter = $request->input('client_id');
        $categoryFilter = $request->input('category_id');
        $unpaidOnly = $request->boolean('unpaid_only', false);

        $items = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            $per_page,
            $search,
            $dateFilter,
            $startDate,
            $endDate,
            $statusFilter,
            $page,
            $projectFilter,
            $clientFilter,
            $categoryFilter,
            $unpaidOnly
        );

        $meta = [
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'next_page' => $items->nextPageUrl(),
            'prev_page' => $items->previousPageUrl(),
        ];

        if (isset($items->unpaid_orders_total)) {
            $meta['unpaid_orders_total'] = $items->unpaid_orders_total;
        }

        return $this->successResponse([
            'items' => OrderResource::collection($items->items())->resolve(),
            'meta' => $meta,
        ]);
    }

    /**
     * Экспорт заказов в Excel (по фильтру или по выбранным id).
     *
     * @param Request $request
     * @return BinaryFileResponse
     */
    public function export(Request $request): BinaryFileResponse
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $this->authorize('viewAny', Order::class);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $statusFilter = $request->input('status_id');
        $projectFilter = $request->input('project_id');
        $clientFilter = $request->input('client_id');
        $categoryFilter = $request->input('category_id');
        $unpaidOnly = $request->boolean('unpaid_only', false);
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = $ids ? [$ids] : [];
        }
        $ids = array_filter(array_map('intval', $ids));
        $orders = $this->itemsRepository->getItemsForExport(
            userUuid: $userUuid,
            search: $search,
            dateFilter: $dateFilter,
            startDate: $startDate,
            endDate: $endDate,
            statusFilter: $statusFilter,
            projectFilter: $projectFilter,
            clientFilter: $clientFilter,
            categoryFilter: $categoryFilter,
            unpaidOnly: $unpaidOnly,
            ids: $ids ?: null,
            limit: 10000
        );
        $exportColumns = [
            'id' => ['№', fn ($o) => $o->id],
            'date' => ['Дата', fn ($o) => $o->date ? (is_string($o->date) ? $o->date : $o->date->format('Y-m-d H:i')) : ''],
            'status_name' => ['Статус', fn ($o) => $o->status_name ?? ''],
            'cash_name' => ['Касса', fn ($o) => $o->cash_name ?? ''],
            'warehouse_name' => ['Склад', fn ($o) => $o->warehouse_name ?? ''],
            'client' => ['Клиент', fn ($o) => trim(($o->client_first_name ?? '') . ' ' . ($o->client_last_name ?? ''))],
            'project_name' => ['Проект', fn ($o) => $o->project_name ?? ''],
            'total_price' => ['Сумма', fn ($o) => (float) ($o->total_price ?? 0)],
            'payment_status_text' => ['Оплата', fn ($o) => $o->getAttribute('payment_status_text') ?? ''],
            'note' => ['Примечание', fn ($o) => $o->note ?? ''],
        ];
        $requestColumns = $request->input('columns');
        $columnKeys = is_array($requestColumns) && !empty($requestColumns)
            ? array_values(array_intersect($requestColumns, array_keys($exportColumns)))
            : array_keys($exportColumns);
        if (empty($columnKeys)) {
            $columnKeys = array_keys($exportColumns);
        }
        $headings = array_map(fn ($key) => $exportColumns[$key][0], $columnKeys);
        $rows = $orders->map(function ($order) use ($exportColumns, $columnKeys) {
            return array_map(fn ($key) => $exportColumns[$key][1]($order), $columnKeys);
        })->all();
        $filename = 'orders_' . date('Y-m-d_His') . '.xlsx';
        return Excel::download(new GenericExport($rows, $headings), $filename, \Maatwebsite\Excel\Excel::XLSX);
    }

    /**
     * Количество заказов на первой стадии (status_id = 1), доступных текущему пользователю.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function firstStageCount()
    {
        $this->requireAuthenticatedUser();
        $this->authorize('viewAny', Order::class);

        $count = $this->itemsRepository->getFirstStageOrdersCount();

        return $this->successResponse(['count' => $count]);
    }

    /**
     * Создать новый заказ
     *
     * @param StoreOrderRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $user = $this->requireAuthenticatedUser();
        $this->authorize('create', Order::class);

        $validatedData = $request->validated();

        $categoryId = $this->resolveCategoryForSimpleUser($validatedData['category_id'] ?? null);
        if ($categoryId instanceof \Illuminate\Http\JsonResponse) {
            return $categoryId;
        }

        $data = [
            'creator_id'      => $userUuid,
            'client_id'    => $validatedData['client_id'],
            'project_id'   => $validatedData['project_id'] ?? null,
            'cash_id'      => $validatedData['cash_id'] ?? null,
            'warehouse_id' => $validatedData['warehouse_id'],
            'currency_id' => $validatedData['currency_id'] ?? null,
            'category_id' => $categoryId,
            'discount' => $validatedData['discount'] ?? 0,
            'discount_type' => $validatedData['discount_type'] ?? 'percent',
            'description' => $validatedData['description'] ?? '',
            'date'         => $validatedData['date'] ?? now(),
            'note'         => $validatedData['note'] ?? '',
            'status_id'    => 1,
            'products'     => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
                'width'      => $p['width'] ?? null,
                'height'     => $p['height'] ?? null,
            ], $validatedData['products'] ?? []),
            'temp_products' => array_map(fn($p) => [
                'name'        => $p['name'],
                'description' => $p['description'] ?? null,
                'quantity'    => $p['quantity'],
                'price'       => $p['price'],
                'unit_id'     => $p['unit_id'] ?? null,
                'width'       => $p['width'] ?? null,
                'height'      => $p['height'] ?? null,
            ], $validatedData['temp_products'] ?? []),
            'client_balance_id' => array_key_exists('client_balance_id', $validatedData)
                ? NullableInt::fromRequest($validatedData['client_balance_id'])
                : null,
        ];


        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id'] ?? null);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        try {
            $order = $this->itemsRepository->createItem($data);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if (isset($validatedData['project_id']) && $validatedData['project_id']) {
                CacheService::invalidateProjectsCache();
            }

            $order = $this->itemsRepository->getItemById($order->id);
            $companyId = (int) $this->getCurrentCompanyId();
            event(new OrderFirstStageCountUpdated($companyId));
            $this->inAppNotificationDispatcher->dispatch(
                $companyId,
                'orders_new',
                $user->id,
                'Новый заказ #'.$order->id,
                null,
                ['route' => '/orders/'.$order->id, 'order_id' => $order->id]
            );
            return (new OrderResource($order))->additional(['message' => 'Заказ успешно создан'])->response();
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * Обновить заказ
     *
     * @param UpdateOrderRequest $request
     * @param int $id ID заказа
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateOrderRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $order = $this->itemsRepository->getItemById($id);
        if (!$order) {
            return $this->errorResponse('Заказ не найден', 404);
        }

        $this->authorize('update', $order);

        $validatedData = $request->validated();

        $categoryId = $this->resolveCategoryForSimpleUser($validatedData['category_id'] ?? null);
        if ($categoryId instanceof \Illuminate\Http\JsonResponse) {
            return $categoryId;
        }

        $data = [
            'client_id'    => $validatedData['client_id'],
            'project_id'   => $validatedData['project_id'] ?? null,
            'cash_id'      => $validatedData['cash_id'] ?? null,
            'warehouse_id'  => $validatedData['warehouse_id'],
            'currency_id'   => $validatedData['currency_id'] ?? null,
            'category_id' => $categoryId,
            'discount'      => $validatedData['discount'] ?? 0,
            'discount_type' => $validatedData['discount_type'] ?? 'percent',
            'note'         => $validatedData['note'] ?? '',
            'description'  => $validatedData['description'] ?? '',
            'status_id'    => $validatedData['status_id'] ?? null,
            'date'         => $validatedData['date'] ?? now(),
            'products'     => array_map(fn($p) => [
                'id'         => $p['id'] ?? null,
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
                'width'      => $p['width'] ?? null,
                'height'     => $p['height'] ?? null,
            ], $validatedData['products'] ?? []),
            'temp_products' => array_map(fn($p) => [
                'id'          => $p['id'] ?? null,
                'name'        => $p['name'],
                'description' => $p['description'] ?? null,
                'quantity'    => $p['quantity'],
                'price'       => $p['price'],
                'unit_id'     => $p['unit_id'] ?? null,
                'width'       => $p['width'] ?? null,
                'height'      => $p['height'] ?? null,
            ], $validatedData['temp_products'] ?? []),
            'remove_temp_products' => $validatedData['remove_temp_products'] ?? [],
        ];
        if (array_key_exists('client_balance_id', $validatedData)) {
            $data['client_balance_id'] = NullableInt::fromRequest($validatedData['client_balance_id']);
        }


        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id'] ?? null);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        try {
            $this->itemsRepository->updateItem($id, $data);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if (isset($validatedData['project_id']) && $validatedData['project_id']) {
                CacheService::invalidateProjectsCache();
            }

            $updatedOrder = $this->itemsRepository->getItemById($id);
            event(new OrderFirstStageCountUpdated((int) $this->getCurrentCompanyId()));
            return (new OrderResource($updatedOrder))->additional(['message' => 'Заказ сохранён'])->response();
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * Удалить заказ
     *
     * @param int $id ID заказа
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $order = $this->itemsRepository->getItemById($id);
        if (!$order) {
            return $this->errorResponse('Заказ не найден', 404);
        }

        $this->requireAuthenticatedUser();
        $this->authorize('delete', $order);

        $cashAccessCheck = $this->checkCashRegisterAccess($order->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        try {
            $deleted = $this->itemsRepository->deleteItem($id);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($order->project_id) {
                CacheService::invalidateProjectsCache();
            }

            event(new OrderFirstStageCountUpdated((int) $this->getCurrentCompanyId()));
            return $this->successResponse($deleted, 'Заказ успешно удалён');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка при удалении заказа: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Массовое обновление статуса заказов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchUpdateStatus(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer|exists:orders,id',
            'status_id' => 'required|integer|exists:order_statuses,id',
        ]);

        $this->requireAuthenticatedUser();
        $orders = Order::whereIn('id', $request->ids)->get();
        foreach ($orders as $order) {
            $this->authorize('update', $order);
        }

        try {
            $result = $this->itemsRepository
                ->updateStatusByIds($request->ids, $request->status_id);

            if (is_array($result) && isset($result['needs_payment']) && $result['needs_payment']) {
                return response()->json([
                    'error' => $result['message'] ?? 'Требуется оплата',
                    'needs_payment' => true,
                    'order_id' => $result['order_id'] ?? null,
                    'remaining_amount' => $result['remaining_amount'] ?? null,
                    'paid_total' => $result['paid_total'] ?? null,
                    'order_total' => $result['order_total'] ?? null,
                ], 422);
            }

            if ($result > 0) {
                CacheService::invalidateOrdersCache();
                event(new OrderFirstStageCountUpdated((int) $this->getCurrentCompanyId()));
                return $this->successResponse(null, "Статус обновлён у {$result} заказ(ов)");
            } else {
                return $this->successResponse(null, "Статус не изменился");
            }
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage() ?: 'Ошибка смены статуса', 400);
        }
    }

    /**
     * Получить заказ по ID
     *
     * @param int $id ID заказа
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $item = $this->itemsRepository->getItemById($id);
        if (!$item) {
            return $this->errorResponse('Not found', 404);
        }

        $this->requireAuthenticatedUser();
        $this->authorize('view', $item);

        $cashAccessCheck = $this->checkCashRegisterAccess($item->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        return (new OrderResource($item))->response();
    }

    /**
     * Получить время сервера
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getServerTime()
    {
        return $this->successResponse([
            'success' => true,
            'server_time' => now()->toISOString(),
            'timestamp' => now()->timestamp
        ]);
    }

    /**
     * @return int|\Illuminate\Http\JsonResponse
     */
    protected function resolveCategoryForSimpleUser(?int $categoryId)
    {
        $user = auth('api')->user();
        if (! SimpleUser::matches($user)) {
            return $categoryId;
        }

        $primaryId = SimpleUser::rootCategoryIdForCurrentCompany($user);
        if ($primaryId === null) {
            return $this->errorResponse('Не настроена основная категория заказов (simple).', 400);
        }

        if ($categoryId !== null && (int) $categoryId !== $primaryId) {
            return $this->errorResponse('У вас нет доступа к указанной категории', 403);
        }

        return $primaryId;
    }
}
