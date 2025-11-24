<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Repositories\OrdersRepository;
use App\Services\CacheService;
use App\Services\OrderService;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с заказами
 */
class OrderController extends Controller
{

    protected $itemRepository;

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * Конструктор контроллера
     *
     * @param OrdersRepository $itemRepository Репозиторий заказов
     * @param OrderService $orderService
     */
    public function __construct(OrdersRepository $itemRepository, OrderService $orderService)
    {
        $this->itemRepository = $itemRepository;
        $this->orderService = $orderService;
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

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $per_page, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter);

        return OrderResource::collection($items)->response();
    }

    /**
     * Создать новый заказ
     *
     * @param StoreOrderRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderRequest $request)
    {
        $user = $this->requireAuthenticatedUser();

        if (!empty($request->temp_products)) {
            $this->authorize('createTemp', Product::class);
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
            $data = $this->orderService->prepareOrderData($request, $user);
            $companyId = $this->getCurrentCompanyId();
            $order = $this->orderService->createOrder($data, $user, $companyId);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return $this->dataResponse(new OrderResource($order), 'Заказ успешно создан');
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
        $user = $this->requireAuthenticatedUser();
        $order = Order::findOrFail($id);

        $this->authorize('update', $order);

        if (!empty($request->temp_products)) {
            $this->authorize('createTemp', Product::class);
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
            $data = $this->orderService->prepareOrderData($request, $user);
            $companyId = $this->getCurrentCompanyId();
            $order = $this->orderService->updateOrder($order, $data, $user, $companyId);

            CacheService::invalidateOrdersCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return $this->dataResponse(new OrderResource($order), 'Заказ сохранён');
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
        $order = Order::findOrFail($id);

        $this->authorize('delete', $order);

        if ($order->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($order->cash_id);
            if ($cashRegister) {
                $this->authorize('view', $cashRegister);
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

            return $this->dataResponse(['order' => $deleted], 'Заказ успешно удалён');
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
            $this->authorize('update', $order);
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
        $order = Order::findOrFail($id);

        $this->authorize('view', $order);

        if ($order->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($order->cash_id);
            if ($cashRegister) {
                $this->authorize('view', $cashRegister);
            }
        }

        $order = Order::with([
            'client', 'user', 'status', 'category', 'cash', 'warehouse', 'project',
            'orderProducts.product', 'orderProducts.product.unit'
        ])->findOrFail($id);

        return $this->dataResponse(new OrderResource($order));
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
