<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Order;
use App\Repositories\TransactionsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    protected $itemsRepository;

    public function __construct(TransactionsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 10);
        $cash_register_id = $request->query('cash_id');
        $date_filter_type = $request->query('date_filter_type');
        $order_id = $request->query('order_id');
        $search = $request->query('search');
        $transaction_type = $request->query('transaction_type');
        $source = $request->query('source');
        $project_id = $request->query('project_id');
        $start_date = $request->query('start_date');
        $end_date = $request->query('end_date');
        $is_debt = $request->query('is_debt');

        $items = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            $per_page,
            $page,
            $cash_register_id,
            $date_filter_type,
            $order_id,
            $search,
            $transaction_type,
            $source,
            $project_id,
            $start_date,
            $end_date,
            $is_debt
        );

        $response = [
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
            'total_debt_positive' => $items->total_debt_positive ?? 0,
            'total_debt_negative' => $items->total_debt_negative ?? 0,
            'total_debt_balance' => $items->total_debt_balance ?? 0
        ];

        return response()->json($response);
    }

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'type' => 'required|integer|in:1,0',
            'orig_amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'cash_id' => 'required|exists:cash_registers,id',
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'is_debt' => 'nullable|boolean'
        ]);

        $cashRegister = \App\Models\CashRegister::find($request->cash_id);
        if (!$cashRegister) {
            return $this->notFoundResponse('Касса не найдена');
        }
        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $sourceType = null;
        $sourceId = null;

        if ($request->has('source_type') || $request->has('source_id')) {
            $sourceType = $request->source_type;
            $sourceId = $request->source_id;
        }
        elseif ($request->order_id) {
            $sourceType = Order::class;
            $sourceId = $request->order_id;
        }

        if ($sourceType === Order::class && $sourceId) {
            $order = Order::find($sourceId);
            if ($order) {
                $orderTotal = $order->price - $order->discount;

                $paidTotal = Transaction::where('source_type', Order::class)
                    ->where('source_id', $order->id)
                    ->where('is_debt', 0)
                    ->sum('orig_amount');

                $remainingAmount = $orderTotal - $paidTotal;

                if ($request->orig_amount < $remainingAmount) {
                    return response()->json([
                        'message' => "Минимальная сумма оплаты: {$remainingAmount}. Указано: {$request->orig_amount}",
                        'error' => 'INSUFFICIENT_PAYMENT_AMOUNT',
                        'minimum_amount' => $remainingAmount,
                        'provided_amount' => $request->orig_amount
                    ], 422);
                }
            }
        }

        $item_created = $this->itemsRepository->createItem([
            'type' => $request->type,
            'user_id' => $userUuid,
            'orig_amount' => $request->orig_amount,
            'currency_id' => $request->currency_id,
            'cash_id' => $request->cash_id,
            'category_id' => $request->category_id,
            'project_id' => $request->project_id,
            'client_id' => $request->client_id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $request->note,
            'date' => $request->date ?? now(),
            'is_debt' => $request->is_debt ?? false
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($request->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ($request->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Транзакция создана']);
    }

    public function update(Request $request, $id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $request->validate([
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'orig_amount' => 'nullable|sometimes|numeric|min:0.01',
            'currency_id' => 'nullable|sometimes|exists:currencies,id',
            'is_debt' => 'nullable|boolean',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer'
        ]);

        $transaction_exist = Transaction::where('id', $id)->first();
        if (!$transaction_exist) {
            return $this->notFoundResponse('Транзакция не найдена');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('transactions', 'update', $transaction_exist)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этой транзакции');
        }

        if ($this->isRestrictedTransaction($transaction_exist)) {
            $message = $this->getRestrictedTransactionMessage($transaction_exist);
            return $this->forbiddenResponse($message);
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($transaction_exist->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }
        $updateSourceType = null;
        $updateSourceId = null;

        if ($request->has('source_type') || $request->has('source_id')) {
            $updateSourceType = $request->input('source_type');
            $updateSourceId = $request->input('source_id');
        }
        elseif ($request->has('order_id')) {
            if ($request->order_id) {
                $updateSourceType = Order::class;
                $updateSourceId = $request->order_id;
            } else {
                $updateSourceType = null;
                $updateSourceId = null;
            }
        }

        $updateData = [
            'category_id' => $request->category_id,
            'project_id' => $request->project_id,
            'client_id' => $request->client_id,
            'note' => $request->note,
            'date' => $request->date ?? now(),
            'is_debt' => $request->is_debt ?? false
        ];

        if ($request->has('orig_amount')) {
            $updateData['orig_amount'] = $request->orig_amount;
        } elseif ($request->has('amount')) {
            $updateData['orig_amount'] = $request->amount;
        }
        if ($request->has('currency_id')) {
            $updateData['currency_id'] = $request->currency_id;
        }

        if ($request->has('source_type') || $request->has('source_id') || $request->has('order_id')) {
            $updateData['source_type'] = $updateSourceType;
            $updateData['source_id'] = $updateSourceId;
        }

        $category_updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($request->client_id || $transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ($request->project_id || $transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Транзакция обновлена']);
    }

    public function destroy($id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $transaction_exist = Transaction::where('id', $id)->first();
        if (!$transaction_exist) {
            return $this->notFoundResponse('Транзакция не найдена');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('transactions', 'delete', $transaction_exist)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этой транзакции');
        }

        if ($this->isRestrictedTransaction($transaction_exist)) {
            $message = $this->getRestrictedTransactionMessage($transaction_exist);
            return $this->forbiddenResponse($message);
        }

        $transaction_deleted = $this->itemsRepository->deleteItem($id);

        if (!$transaction_deleted) {
            return $this->errorResponse('Ошибка удаления транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ($transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Транзакция удалена']);
    }

    public function getTotalByOrderId(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $orderId = $request->query('order_id');
        if (!$orderId) {
            return $this->errorResponse('order_id is required', 400);
        }

        $total = $this->itemsRepository->getTotalByOrderId($userUuid, $orderId);
        return response()->json(['total' => $total]);
    }

    public function show($id)
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return $this->notFoundResponse('Транзакция не найдена');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('transactions', 'view', $transaction)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этой транзакции');
        }

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemsRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }
        return response()->json(['item' => $item]);
    }

    private function isRestrictedTransaction($transaction)
    {
        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return true;
        }

        if ($transaction->source_type && $transaction->source_id) {
            return true;
        }

        return false;
    }

    private function getRestrictedTransactionMessage($transaction)
    {
        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с переводом между кассами';
        }

        if ($transaction->source_type && $transaction->source_id) {
            $sourceType = class_basename($transaction->source_type);

            switch ($sourceType) {
                case 'Sale':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через продажу. Управляйте ей через раздел "Продажи"';
                case 'Order':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через заказ. Управляйте ей через раздел "Заказы"';
                case 'WhReceipt':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через складское поступление. Управляйте ей через раздел "Склад"';
                default:
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с другой операцией в системе';
            }
        }

        return 'Нельзя редактировать/удалить эту транзакцию';
    }

}
