<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Repositories\OrdersRepository;
use App\Services\CacheService;
use App\Models\Order;
use App\Models\User;
use App\Models\Category;
use App\Models\CategoryUser;
use Illuminate\Http\Request;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;

/**
 * Контроллер для работы с заказами
 */
class OrderController extends BaseController
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
        $user = $this->requireAuthenticatedUser();

        $resource = $this->getOrderResourceForUser($user);

        if (!$this->canPerformAction($resource, 'view')) {
            return $this->forbiddenResponse('У вас нет прав на просмотр заказов');
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

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter, $unpaidOnly);

        $unpaidOrdersTotal = $items->unpaid_orders_total ?? null;

        return new OrderCollection($items, $unpaidOrdersTotal);
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
            return $this->forbiddenResponse('У вас нет прав на создание заказов');
        }

        $validatedData = $request->validated();

        $categoryId = $this->resolveCategoryForSimpleWorker($validatedData['category_id'] ?? null, $userUuid);
        if ($categoryId instanceof \Illuminate\Http\JsonResponse) {
            return $categoryId;
        }

        $data = [
            'user_id'      => $userUuid,
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
            $order = $this->itemRepository->createItem($data);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if (isset($validatedData['project_id']) && $validatedData['project_id']) {
                CacheService::invalidateProjectsCache();
            }

            $order = $this->itemRepository->getItemById($order->id);
            return (new OrderResource($order))->additional(['message' => 'Заказ успешно создан']);
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

        $order = $this->itemRepository->getItemById($id);
        if (!$order) {
            return $this->notFoundResponse('Заказ не найден');
        }

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'update', $order)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого заказа');
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
            $this->itemRepository->updateItem($id, $data);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if (isset($validatedData['project_id']) && $validatedData['project_id']) {
                CacheService::invalidateProjectsCache();
            }

            $updatedOrder = $this->itemRepository->getItemById($id);
            return (new OrderResource($updatedOrder))->additional(['message' => 'Заказ сохранён']);
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
        $order = $this->itemRepository->getItemById($id);
        if (!$order) {
            return $this->notFoundResponse('Заказ не найден');
        }

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'delete', $order)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого заказа');
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($order->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
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

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        $orders = Order::whereIn('id', $request->ids)->get();
        foreach ($orders as $order) {
            if (!$this->canPerformAction($resource, 'update', $order)) {
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
        $item = $this->itemRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }

        $user = $this->requireAuthenticatedUser();
        $resource = $this->getOrderResourceForUser($user);
        if (!$this->canPerformAction($resource, 'view', $item)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этого заказа');
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($item->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        return new OrderResource($item);
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
                return $this->forbiddenResponse('У вас нет доступа к указанной категории');
            }
            return $categoryId;
        }

        $companyId = $this->getCurrentCompanyId();
        $userCategoryIds = CategoryUser::where('user_id', $userUuid)
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
            return $this->forbiddenResponse('У вас нет доступа к указанной категории');
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
