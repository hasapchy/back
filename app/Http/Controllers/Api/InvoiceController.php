<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Repositories\InvoicesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со счетами
 */
class InvoiceController extends BaseController
{
    protected $itemRepository;

    /**
     * Конструктор контроллера
     *
     * @param InvoicesRepository $itemRepository
     */
    public function __construct(InvoicesRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

    /**
     * Получить список счетов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $typeFilter = $request->input('type');
        $statusFilter = $request->input('status');
        $perPage = $request->input('per_page', 20);

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $typeFilter, $statusFilter, $page);

        return $this->paginatedResponse($items);
    }

    /**
     * Создать новый счет
     *
     * @param StoreInvoiceRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreInvoiceRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        try {
            $orders = $this->itemRepository->getOrdersForInvoice($validatedData['order_ids']);

            if ($orders->isEmpty()) {
                return $this->errorResponse('Заказы не найдены', 400);
            }

            $clientId = $orders->first()->client_id;
            if ($orders->where('client_id', '!=', $clientId)->isNotEmpty()) {
                return $this->errorResponse('Все заказы должны принадлежать одному клиенту', 400);
            }

            $productsData = $this->itemRepository->prepareProductsFromOrders($orders);
            $products = $productsData['products'];

            $totalAmount = collect($products)->sum('total_price');

            $data = [
                'client_id' => $validatedData['client_id'],
                'user_id' => $userUuid,
                'invoice_date' => $validatedData['invoice_date'] ?? now()->toDateString(),
                'note' => $validatedData['note'] ?? '',
                'order_ids' => $validatedData['order_ids'],
                'products' => $products,
                'total_amount' => $totalAmount,
            ];

            $created = $this->itemRepository->createItem($data);

            if (!$created) {
                return $this->errorResponse('Ошибка создания счета', 400);
            }

            return response()->json(['invoice' => $created, 'message' => 'Счет успешно создан']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка создания счета: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Обновить счет
     *
     * @param UpdateInvoiceRequest $request
     * @param int $id ID счета
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateInvoiceRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        try {
            $data = [
                'client_id' => $validatedData['client_id'],
                'invoice_date' => $validatedData['invoice_date'] ?? null,
                'note' => $validatedData['note'] ?? null,
                'status' => $validatedData['status'] ?? null,
                'order_ids' => $validatedData['order_ids'] ?? null,
                'products' => $validatedData['products'] ?? null,
                'total_amount' => $request->input('total_amount'),
            ];

            $updated = $this->itemRepository->updateItem($id, $data);
            if (!$updated) {
                return $this->errorResponse('Ошибка обновления счета', 400);
            }

            return response()->json(['message' => 'Счет сохранён']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Удалить счет
     *
     * @param int $id ID счета
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $deleted = $this->itemRepository->deleteItem($id);

            return response()->json(['invoice' => $deleted, 'message' => 'Счет успешно удалён']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка при удалении счета: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Получить счет по ID
     *
     * @param int $id ID счета
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $item = $this->itemRepository->getItemById($id);
        return response()->json(['item' => $item]);
    }

    /**
     * Получить заказы для счета
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrdersForInvoice(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        try {
            $orders = $this->itemRepository->getOrdersForInvoice($request->order_ids);
            $productsData = $this->itemRepository->prepareProductsFromOrders($orders);
            $products = $productsData['products'];
            $orderDate = $productsData['order_date'];

            return response()->json([
                'orders' => $orders,
                'products' => $products,
                'order_date' => $orderDate,
                'total_amount' => collect($products)->sum('total_price')
            ]);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка получения данных: ' . $th->getMessage(), 400);
        }
    }
}
