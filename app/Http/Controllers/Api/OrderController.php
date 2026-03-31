<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Repositories\OrdersRepository;
use App\Services\CacheService;
use App\Models\Order;
use App\Models\User;
use App\Models\Category;
use App\Models\CategoryUser;
use Illuminate\Http\Request;
use App\Events\OrderFirstStageCountUpdated;
use App\Exports\GenericExport;
use App\Http\Resources\OrderResource;
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
    public function __construct(OrdersRepository $itemsRepository)
    {
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
        $user = $this->requireAuthenticatedUser();

        $resource = $this->getOrderResourceForUser($user);

        if (!$this->canPerformAction($resource, 'view')) {
            return $this->errorResponse('У вас нет прав на просмотр заказов', 403);
        }

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 20);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $statusFilter = $request->input('status_id');
        $projectFilter = $request->input('project_id');
        $clientFilter = $request->input('client_id');
        $unpaidOnly = $request->boolean('unpaid_only', false);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter, $unpaidOnly);

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
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $statusFilter = $request->input('status_id');
        $projectFilter = $request->input('project_id');
        $clientFilter = $request->input('client_id');
        $unpaidOnly = $request->boolean('unpaid_only', false);
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = $ids ? [$ids] : [];
        }
        $ids = array_filter(array_map('intval', $ids));
        $orders = $this->itemsRepository->getItemsForExport(
            $userUuid,
            $search,
            $dateFilter,
            $startDate,
            $endDate,
            $statusFilter,
            $projectFilter,
            $clientFilter,
            $unpaidOnly,
            $ids ?: null,
            10000
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
        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (! $this->canPerformAction($resource, 'view')) {
            return $this->errorResponse('У вас нет прав на просмотр заказов', 403);
        }

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

        // Проверка прав на создание заказов
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'create')) {
            return $this->errorResponse('У вас нет прав на создание заказов', 403);
        }

        $validatedData = $request->validated();

        $categoryId = $this->resolveCategoryForSimpleWorker($validatedData['category_id'] ?? null, $userUuid);
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
            event(new OrderFirstStageCountUpdated((int) $this->getCurrentCompanyId()));
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

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'update', $order)) {
            return $this->errorResponse('У вас нет прав на редактирование этого заказа', 403);
        }

        $validatedData = $request->validated();

        $categoryId = $this->resolveCategoryForSimpleWorker($validatedData['category_id'] ?? null, $userUuid);
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

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'delete', $order)) {
            return $this->errorResponse('У вас нет прав на удаление этого заказа', 403);
        }

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

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        $orders = Order::whereIn('id', $request->ids)->get();
        foreach ($orders as $order) {
            if (!$this->canPerformAction($resource, 'update', $order)) {
                return $this->errorResponse('У вас нет прав на редактирование одного или нескольких заказов', 403);
            }
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

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'view', $item)) {
            return $this->errorResponse('У вас нет прав на просмотр этого заказа', 403);
        }

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
     * Разрешить категорию для simple worker
     *
     * @param int|null $categoryId ID категории
     * @param int $userUuid ID пользователя
     * @return int|\Illuminate\Http\JsonResponse
     */
    protected function resolveCategoryForSimpleWorker(?int $categoryId, int $userUuid)
    {
        $user = auth('api')->user();
        if (!($user instanceof User && $user->hasRole(config('simple.worker_role')))) {
            return $categoryId;
        }

        $mapping = config('simple.user_category_mapping', []);
        $mappedCategoryId = $mapping[$userUuid] ?? null;

        if ($mappedCategoryId) {
            if (!$categoryId) {
                $categoryId = $mappedCategoryId;
            } elseif ($categoryId != $mappedCategoryId) {
                return $this->errorResponse('У вас нет доступа к указанной категории', 403);
            }
            return $categoryId;
        }

        $companyId = $this->getCurrentCompanyId();
        $userCategoryIds = CategoryUser::where('creator_id', $userUuid)
            ->pluck('category_id')
            ->toArray();

        if ($companyId) {
            $companyCategoryIds = Category::where('company_id', $companyId)
                ->pluck('id')
                ->toArray();
            $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
        }

        if (!$categoryId) {
            $categoryId = !empty($userCategoryIds) ? $userCategoryIds[0] : null;

            if (!$categoryId) {
                return $this->errorResponse('У вас нет доступных категорий. Обратитесь к администратору для назначения категории.', 400);
            }
        } elseif (!in_array($categoryId, $userCategoryIds)) {
            return $this->errorResponse('У вас нет доступа к указанной категории', 403);
        }

        return $categoryId;
    }

    /**
     * Получить ресурс для проверки permissions в зависимости от роли пользователя
     *
     * @param User $user Пользователь
     * @return string Название ресурса ('orders' или 'orders_simple')
     */
    protected function getOrderResourceForUser(User $user): string
    {
        if ($user->hasRole(config('simple.worker_role'))) {
            return 'orders_simple';
        }

        $permissions = $this->getUserPermissions($user);

        foreach ($permissions as $permission) {
            if (str_starts_with($permission, 'orders_simple_')) {
                return 'orders_simple';
            }
        }

        return 'orders';
    }
}
