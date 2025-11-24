<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrdersRepository;
use App\Services\CacheService;
use App\Models\Order;
use App\Models\User;
use App\Models\Category;
use App\Models\CategoryUser;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с заказами
 */
class OrderController extends Controller
{

    protected $itemRepository;

    /**
     * Конструктор контроллера
     *
     * @param OrdersRepository $itemRepository Репозиторий заказов
     */
    public function __construct(OrdersRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
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

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 10);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $statusFilter = $request->input('status_id');
        $projectFilter = $request->input('project_id');
        $clientFilter = $request->input('client_id');
        $unpaidOnly = $request->boolean('unpaid_only', false);

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter, $unpaidOnly);

        $response = [
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ];

        if (isset($items->unpaid_orders_total)) {
            $response['unpaid_orders_total'] = $items->unpaid_orders_total;
        }

        return response()->json($response);
    }

    /**
     * Создать новый заказ
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();


        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'project_id' => 'nullable|sometimes|integer|exists:projects,id',
            'cash_id' => 'nullable|integer|exists:cash_registers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'category_id' => 'required|integer|exists:categories,id',
            'discount'      => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percent|required_with:discount',
            'description' => 'nullable|string',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'status_id'    => 'nullable|integer|exists:order_statuses,id',
            'products'              => 'sometimes|array',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'   => 'required_with:products|numeric|min:0',
            'products.*.price'      => 'required_with:products|numeric|min:0',
            'products.*.width'      => 'nullable|numeric|min:0',
            'products.*.height'    => 'nullable|numeric|min:0',
            'temp_products'         => 'sometimes|array',
            'temp_products.*.name'  => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'temp_products.*.width'       => 'nullable|numeric|min:0',
            'temp_products.*.height'      => 'nullable|numeric|min:0',
        ]);

        $categoryId = $request->category_id;
        $user = auth('api')->user();
        if ($user instanceof User && $user->hasRole(config('basement.worker_role'))) {
            // Получаем категории пользователя с учетом компании
            $companyId = $this->getCurrentCompanyId();
            $userCategoryIds = CategoryUser::where('user_id', $userUuid)
                ->pluck('category_id')
                ->toArray();

            // Фильтруем по компании, если указана
            if ($companyId) {
                $companyCategoryIds = Category::where('company_id', $companyId)
                    ->pluck('id')
                    ->toArray();
                $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
            }

            if (!$categoryId) {
                // Если категория не указана, берем первую доступную категорию пользователя
                $categoryId = !empty($userCategoryIds) ? $userCategoryIds[0] : null;
            } elseif (!in_array($categoryId, $userCategoryIds)) {
                // Если категория указана, проверяем доступ пользователя к ней
                return $this->forbiddenResponse('У вас нет доступа к указанной категории');
            }
        }

        $data = [
            'user_id'      => $userUuid,
            'client_id'    => $request->client_id,
            'project_id'   => $request->project_id,
            'cash_id'      => $request->cash_id,
            'warehouse_id' => $request->warehouse_id,
            'currency_id' => $request->currency_id,
            'category_id' => $categoryId,
            'discount' => $request->discount ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'description' => $request->description ?? '',
            'date'         => $request->date ?? now(),
            'note'         => $request->note ?? '',
            'status_id'    => 1,
            'products'     => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
                'width'      => $p['width'] ?? null,
                'height'     => $p['height'] ?? null,
            ], $request->products ?? []),
            'temp_products' => array_map(fn($p) => [
                'name'        => $p['name'],
                'description' => $p['description'] ?? null,
                'quantity'    => $p['quantity'],
                'price'       => $p['price'],
                'unit_id'     => $p['unit_id'] ?? null,
                'width'       => $p['width'] ?? null,
                'height'      => $p['height'] ?? null,
            ], $request->temp_products ?? []),
        ];

        if (!empty($request->temp_products)) {
            if (!$this->hasPermission('products_create_temp')) {
                return $this->forbiddenResponse('У вас нет прав на создание временных товаров');
            }
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        try {
            $this->itemRepository->createItem($data);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['message' => 'Заказ успешно создан']);
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage(), 400);
        }
    }

    /**
     * Обновить заказ
     *
     * @param Request $request
     * @param int $id ID заказа
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $order = $this->itemRepository->getItemById($id);
        if (!$order) {
            return $this->notFoundResponse('Заказ не найден');
        }

        if (!$this->canPerformAction('orders', 'update', $order)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого заказа');
        }

        $request->validate([
            'client_id'            => 'required|integer|exists:clients,id',
            'project_id'           => 'nullable|sometimes|integer|exists:projects,id',
            'cash_id'              => 'nullable|integer|exists:cash_registers,id',
            'warehouse_id'         => 'required|integer|exists:warehouses,id',
            'currency_id'  => 'nullable|integer|exists:currencies,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'date'                 => 'nullable|date',
            'note'                 => 'nullable|string',
            'description'          => 'nullable|string',
            'status_id'            => 'nullable|integer|exists:order_statuses,id',
            'products'             => 'nullable|array',
            'products.*.id'        => 'nullable|integer|exists:order_products,id',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'  => 'required_with:products|numeric|min:0',
            'products.*.price'     => 'required_with:products|numeric|min:0',
            'products.*.width'      => 'nullable|numeric|min:0',
            'products.*.height'    => 'nullable|numeric|min:0',
            'temp_products'         => 'nullable|array',
            'temp_products.*.id'    => 'nullable|integer|exists:order_temp_products,id',
            'temp_products.*.name'  => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'temp_products.*.width'       => 'nullable|numeric|min:0',
            'temp_products.*.height'      => 'nullable|numeric|min:0',
            'remove_temp_products'  => 'nullable|array',
            'remove_temp_products.*' => 'integer|exists:order_temp_products,id',
        ]);

        $categoryId = $request->category_id;
        $user = auth('api')->user();
        if ($user instanceof User && $user->hasRole(config('basement.worker_role'))) {
            // Получаем категории пользователя с учетом компании
            $companyId = $this->getCurrentCompanyId();
            $userCategoryIds = CategoryUser::where('user_id', $userUuid)
                ->pluck('category_id')
                ->toArray();

            // Фильтруем по компании, если указана
            if ($companyId) {
                $companyCategoryIds = Category::where('company_id', $companyId)
                    ->pluck('id')
                    ->toArray();
                $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
            }

            if (!$categoryId) {
                // Если категория не указана, берем первую доступную категорию пользователя
                $categoryId = !empty($userCategoryIds) ? $userCategoryIds[0] : null;
            } elseif (!in_array($categoryId, $userCategoryIds)) {
                // Если категория указана, проверяем доступ пользователя к ней
                return $this->forbiddenResponse('У вас нет доступа к указанной категории');
            }
        }

        $data = [
            'client_id'    => $request->client_id,
            'project_id'   => $request->project_id,
            'cash_id'      => $request->cash_id,
            'warehouse_id'  => $request->warehouse_id,
            'currency_id'   => $request->currency_id,
            'category_id' => $categoryId,
            'discount'      => $request->discount  ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'note'         => $request->note ?? '',
            'description'  => $request->description ?? '',
            'status_id'    => $request->status_id,
            'date'         => $request->date ?? now(),
            'products'     => array_map(fn($p) => [
                'id'         => $p['id'] ?? null,
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
                'width'      => $p['width'] ?? null,
                'height'     => $p['height'] ?? null,
            ], $request->products ?? []),
            'temp_products' => array_map(fn($p) => [
                'id'          => $p['id'] ?? null,
                'name'        => $p['name'],
                'description' => $p['description'] ?? null,
                'quantity'    => $p['quantity'],
                'price'       => $p['price'],
                'unit_id'     => $p['unit_id'] ?? null,
                'width'       => $p['width'] ?? null,
                'height'      => $p['height'] ?? null,
            ], $request->temp_products ?? []),
            'remove_temp_products' => $request->remove_temp_products ?? [],
        ];

        if (!empty($request->temp_products)) {
            if (!$this->hasPermission('products_create_temp')) {
                return $this->forbiddenResponse('У вас нет прав на создание временных товаров');
            }
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        try {
            $this->itemRepository->updateItem($id, $data);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['message' => 'Заказ сохранён']);
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
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $order = $this->itemRepository->getItemById($id);
        if (!$order) {
            return $this->notFoundResponse('Заказ не найден');
        }

        if (!$this->canPerformAction('orders', 'delete', $order)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого заказа');
        }

        if ($order->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($order->cash_id);
            if ($cashRegister && !$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на эту кассу');
            }
        }

        try {
            $deleted = $this->itemRepository->deleteItem($id);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($order->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['order' => $deleted, 'message' => 'Заказ успешно удалён']);
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
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer|exists:orders,id',
            'status_id' => 'required|integer|exists:order_statuses,id',
        ]);

        $orders = Order::whereIn('id', $request->ids)->get();
        foreach ($orders as $order) {
            if (!$this->canPerformAction('orders', 'update', $order)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование одного или нескольких заказов');
            }
        }

        try {
            $result = $this->itemRepository
                ->updateStatusByIds($request->ids, $request->status_id, $userUuid);

            if (is_array($result) && isset($result['needs_payment']) && $result['needs_payment']) {
                return response()->json($result, 422);
            }

            if ($result > 0) {
                CacheService::invalidateOrdersCache();

                return response()->json(['message' => "Статус обновлён у {$result} заказ(ов)"]);
            } else {
                return response()->json(['message' => "Статус не изменился"]);
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
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }

        if (!$this->canPerformAction('orders', 'view', $item)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этого заказа');
        }

        if ($item->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($item->cash_id);
            if ($cashRegister && !$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на эту кассу');
            }
        }

        return response()->json(['item' => $item]);
    }

    /**
     * Получить время сервера
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getServerTime()
    {
        return response()->json([
            'success' => true,
            'server_time' => now()->toISOString(),
            'timestamp' => now()->timestamp
        ]);
    }
}
