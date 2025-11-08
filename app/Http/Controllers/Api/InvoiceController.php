<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\InvoicesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    protected $itemRepository;

    public function __construct(InvoicesRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

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

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        try {
            $orders = $this->itemRepository->getOrdersForInvoice($request->order_ids);

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
                'client_id' => $request->client_id,
                'user_id' => $userUuid,
                'invoice_date' => $request->invoice_date ?? now()->toDateString(),
                'note' => $request->note ?? '',
                'order_ids' => $request->order_ids,
                'products' => $products,
                'total_amount' => $totalAmount,
            ];

            $created = $this->itemRepository->createItem($data);

            if (!$created) {
                return $this->errorResponse('Ошибка создания счета', 400);
            }

            CacheService::invalidateInvoicesCache();

            return response()->json(['invoice' => $created, 'message' => 'Счет успешно создан']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка создания счета: ' . $th->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => 'nullable|string|in:new,in_progress,paid,cancelled',
            'order_ids' => 'nullable|array',
            'order_ids.*' => 'integer|exists:orders,id',
            'products' => 'nullable|array',
            'products.*.product_name' => 'required_with:products|string|max:255',
            'products.*.quantity' => 'required_with:products|numeric|min:0.01',
            'products.*.price' => 'required_with:products|numeric|min:0',
        ]);

        try {
            $data = [
                'client_id' => $request->client_id,
                'invoice_date' => $request->invoice_date,
                'note' => $request->note,
                'status' => $request->status,
                'order_ids' => $request->order_ids,
                'products' => $request->products,
                'total_amount' => $request->total_amount,
            ];

            $updated = $this->itemRepository->updateItem($id, $data);
            if (!$updated) {
                return $this->errorResponse('Ошибка обновления счета', 400);
            }

            CacheService::invalidateInvoicesCache();

            return response()->json(['message' => 'Счет сохранён']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка: ' . $th->getMessage(), 400);
        }
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $deleted = $this->itemRepository->deleteItem($id);

            CacheService::invalidateInvoicesCache();

            return response()->json(['invoice' => $deleted, 'message' => 'Счет успешно удалён']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка при удалении счета: ' . $th->getMessage(), 400);
        }
    }

    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }
        return response()->json(['item' => $item]);
    }

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
