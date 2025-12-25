<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Transaction;
use App\Models\Order;
use App\Repositories\TransactionsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для работы с транзакциями
 */
class TransactionsController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param TransactionsRepository $itemsRepository Репозиторий транзакций
     */
    public function __construct(TransactionsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список транзакций с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 20);
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

    /**
     * Создать новую транзакцию
     *
     * @param StoreTransactionRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        if (isset($validatedData['is_adjustment']) && $validatedData['is_adjustment']) {
            if (!$this->hasPermission('settings_client_balance_adjustment')) {
                return $this->forbiddenResponse('У вас нет прав на корректировку баланса');
            }
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id']);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $sourceType = null;
        $sourceId = null;

        if (isset($validatedData['source_type']) || isset($validatedData['source_id'])) {
            $sourceType = $validatedData['source_type'] ?? null;
            $sourceId = $validatedData['source_id'] ?? null;
        } elseif (isset($validatedData['order_id']) && $validatedData['order_id']) {
            $sourceType = Order::class;
            $sourceId = $validatedData['order_id'];
        }

        $item_created = $this->itemsRepository->createItem([
            'type' => $validatedData['type'],
            'user_id' => $userUuid,
            'orig_amount' => $validatedData['orig_amount'],
            'currency_id' => $validatedData['currency_id'],
            'cash_id' => $validatedData['cash_id'],
            'category_id' => $validatedData['category_id'],
            'project_id' => $validatedData['project_id'] ?? null,
            'client_id' => $validatedData['client_id'] ?? null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $validatedData['note'] ?? null,
            'date' => $validatedData['date'] ?? now(),
            'is_debt' => $validatedData['is_debt'] ?? false,
            'exchange_rate' => $validatedData['exchange_rate'] ?? null
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if (isset($validatedData['client_id']) && $validatedData['client_id']) {
            CacheService::invalidateClientsCache();
        }
        if (isset($validatedData['project_id']) && $validatedData['project_id']) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Транзакция создана']);
    }

    /**
     * Обновить транзакцию
     *
     * @param UpdateTransactionRequest $request
     * @param int $id ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransactionRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        $transaction_exist = Transaction::findOrFail($id);

        $isAdjustmentCategory = in_array($transaction_exist->category_id, [21, 22]) ||
            (isset($validatedData['category_id']) && in_array($validatedData['category_id'], [21, 22]));

        if ($isAdjustmentCategory) {
            if (!$this->hasPermission('settings_client_balance_adjustment')) {
                return $this->forbiddenResponse('У вас нет прав на корректировку баланса');
            }
        }

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

        if (isset($validatedData['source_type']) || isset($validatedData['source_id'])) {
            $updateSourceType = $validatedData['source_type'] ?? null;
            $updateSourceId = $validatedData['source_id'] ?? null;
        } elseif ($request->has('order_id')) {
            if ($request->order_id) {
                $updateSourceType = Order::class;
                $updateSourceId = $request->order_id;
            } else {
                $updateSourceType = null;
                $updateSourceId = null;
            }
        }

        $updateData = [
            'category_id' => $validatedData['category_id'],
            'project_id' => $validatedData['project_id'] ?? null,
            'client_id' => $validatedData['client_id'] ?? null,
            'note' => $validatedData['note'] ?? null,
            'date' => $validatedData['date'] ?? now(),
            'is_debt' => $validatedData['is_debt'] ?? false
        ];

        if (isset($validatedData['orig_amount'])) {
            $updateData['orig_amount'] = $validatedData['orig_amount'];
        } elseif ($request->has('amount')) {
            $updateData['orig_amount'] = $request->amount;
        }
        if (isset($validatedData['currency_id'])) {
            $updateData['currency_id'] = $validatedData['currency_id'];
        }

        if (isset($validatedData['exchange_rate'])) {
            $updateData['exchange_rate'] = $validatedData['exchange_rate'];
        }

        Log::info('transaction.update.payload', [
            'transaction_id' => $id,
            'user_id' => $userUuid,
            'exchange_rate_from_request' => $validatedData['exchange_rate'] ?? null,
            'update_data' => $updateData,
        ]);

        if (isset($validatedData['source_type']) || isset($validatedData['source_id']) || $request->has('order_id')) {
            $updateData['source_type'] = $updateSourceType;
            $updateData['source_id'] = $updateSourceId;
        }

        $category_updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ((isset($validatedData['client_id']) && $validatedData['client_id']) || $transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ((isset($validatedData['project_id']) && $validatedData['project_id']) || $transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Транзакция обновлена']);
    }

    /**
     * Удалить транзакцию
     *
     * @param int $id ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $transaction_exist = Transaction::findOrFail($id);

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

    /**
     * Получить сумму транзакций по заказу
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
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

    /**
     * Получить транзакцию по ID
     *
     * @param int $id ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $this->getAuthenticatedUserIdOrFail();
        
        $transaction = Transaction::findOrFail($id);

        if (!$this->canPerformAction('transactions', 'view', $transaction)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этой транзакции');
        }

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
            $sourceType = class_basename($transaction->source_type);

            if ($sourceType === 'EmployeeSalary') {
                return false;
            }

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
                case 'EmployeeSalary':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с зарплатой сотрудника. Управляйте ей через раздел "Сотрудники"';
                default:
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с другой операцией в системе';
            }
        }

        return 'Нельзя редактировать/удалить эту транзакцию';
    }
}
