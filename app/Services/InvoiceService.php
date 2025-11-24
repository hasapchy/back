<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Repositories\InvoicesRepository;
use Illuminate\Support\Collection;

class InvoiceService
{
    /**
     * @var InvoicesRepository
     */
    protected $repository;

    /**
     * @param InvoicesRepository $repository
     */
    public function __construct(InvoicesRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Создать счет из заказов
     *
     * @param array $orderIds
     * @param array $data
     * @param User $user
     * @return Invoice
     * @throws \Exception
     */
    public function createFromOrders(array $orderIds, array $data, User $user): Invoice
    {
        $orders = $this->repository->getOrdersForInvoice($orderIds);

        if ($orders->isEmpty()) {
            throw new \Exception('Заказы не найдены');
        }

        $this->validateOrdersForInvoice($orders);

        $productsData = $this->repository->prepareProductsFromOrders($orders);
        $products = $productsData['products'];

        $totalAmount = collect($products)->sum('total_price');

        $invoiceData = [
            'client_id' => $data['client_id'] ?? $orders->first()->client_id,
            'user_id' => $user->id,
            'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
            'note' => $data['note'] ?? '',
            'order_ids' => $orderIds,
            'products' => $products,
            'total_amount' => $totalAmount,
        ];

        $created = $this->repository->createItem($invoiceData);

        if (!$created) {
            throw new \Exception('Ошибка создания счета');
        }

        return Invoice::with(['client', 'user', 'orders', 'products.product', 'products.unit'])->findOrFail($created->id);
    }

    /**
     * Валидировать заказы для создания счета
     *
     * @param Collection $orders
     * @return bool
     * @throws \Exception
     */
    public function validateOrdersForInvoice(Collection $orders): bool
    {
        if ($orders->isEmpty()) {
            throw new \Exception('Заказы не найдены');
        }

        $clientId = $orders->first()->client_id;
        if ($orders->where('client_id', '!=', $clientId)->isNotEmpty()) {
            throw new \Exception('Все заказы должны принадлежать одному клиенту');
        }

        return true;
    }

    /**
     * Обновить счет
     *
     * @param Invoice $invoice
     * @param array $data
     * @return Invoice
     */
    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        $updated = $this->repository->updateItem($invoice->id, $data);

        if (!$updated) {
            throw new \Exception('Ошибка обновления счета');
        }

        return Invoice::with(['client', 'user', 'orders', 'products.product', 'products.unit'])->findOrFail($invoice->id);
    }
}

