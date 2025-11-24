<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetOrdersForInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\OrderResource;
use App\Models\Invoice;
use App\Repositories\InvoicesRepository;
use App\Services\CacheService;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со счетами
 */
class InvoiceController extends Controller
{
    protected $itemRepository;

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * Конструктор контроллера
     *
     * @param InvoicesRepository $itemRepository
     * @param InvoiceService $invoiceService
     */
    public function __construct(InvoicesRepository $itemRepository, InvoiceService $invoiceService)
    {
        $this->itemRepository = $itemRepository;
        $this->invoiceService = $invoiceService;
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

        return InvoiceResource::collection($items)->response();
    }

    /**
     * Создать новый счет
     *
     * @param StoreInvoiceRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreInvoiceRequest $request)
    {
        $user = $this->requireAuthenticatedUser();

        try {
            $data = [
                'client_id' => $request->client_id,
                'invoice_date' => $request->invoice_date ?? now()->toDateString(),
                'note' => $request->note ?? '',
            ];

            $invoice = $this->invoiceService->createFromOrders($request->order_ids, $data, $user);

            return $this->dataResponse(new InvoiceResource($invoice), 'Счет успешно создан');
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
        $invoice = Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

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

            $invoice = $this->invoiceService->updateInvoice($invoice, $data);

            return $this->dataResponse(new InvoiceResource($invoice), 'Счет сохранён');
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
        $invoice = Invoice::findOrFail($id);

        $this->authorize('delete', $invoice);

        try {
            $deleted = $this->itemRepository->deleteItem($id);

            return $this->dataResponse(['invoice' => $deleted], 'Счет успешно удалён');
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
        $invoice = Invoice::findOrFail($id);

        $this->authorize('view', $invoice);

        $invoice = Invoice::with(['client', 'user', 'orders', 'products.product', 'products.unit'])->findOrFail($id);
        return $this->dataResponse(new InvoiceResource($invoice));
    }

    /**
     * Получить заказы для счета
     *
     * @param GetOrdersForInvoiceRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrdersForInvoice(GetOrdersForInvoiceRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $orders = $this->itemRepository->getOrdersForInvoice($request->order_ids);
            $productsData = $this->itemRepository->prepareProductsFromOrders($orders);
            $products = $productsData['products'];
            $orderDate = $productsData['order_date'];

            return $this->dataResponse([
                'orders' => OrderResource::collection($orders)->resolve(),
                'products' => $products,
                'order_date' => $orderDate,
                'total_amount' => collect($products)->sum('total_price')
            ]);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка получения данных: ' . $th->getMessage(), 400);
        }
    }
}
