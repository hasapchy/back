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
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

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

        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
    }

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        // Валидация данных
        $request->validate([
            'type' => 'required|integer|in:1,0',
            'orig_amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'cash_id' => 'required|exists:cash_registers,id',
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'is_debt' => 'nullable|boolean'
        ]);

        $userHasPermissionToCashRegister = $this->itemsRepository->userHasPermissionToCashRegister($userUuid, $request->cash_id);

        if (!$userHasPermissionToCashRegister) {
            return response()->json([
                'message' => 'У вас нет прав на эту кассу'
            ], 403);
        }

        // Поддержка новой morph-связи источника (заказ)
        $sourceType = null;
        $sourceId = null;
        if ($request->order_id) {
            $sourceType = Order::class;
            $sourceId = $request->order_id;
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
            // morph-источник вместо order_id
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $request->note,
            'date' => $request->date ?? now(),
            'is_debt' => $request->is_debt ?? false
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания транзакции'
            ], 400);
        }

        // Инвалидируем кэш транзакций
        CacheService::invalidateTransactionsCache();
        // Инвалидируем кэш клиентов (баланс клиента изменился)
        if ($request->client_id) {
            CacheService::invalidateClientsCache();
        }
        // Инвалидируем кэш проектов (если транзакция привязана к проекту)
        if ($request->project_id) {
            CacheService::invalidateProjectsCache();
        }
        // Инвалидируем кэш касс (баланс кассы изменился)
        CacheService::invalidateCashRegistersCache();

        return response()->json([
            'message' => 'Транзакция создана'
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        $userUuid = optional($user)->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $request->validate([
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'orig_amount' => 'nullable|sometimes|numeric|min:0.01',
            'currency_id' => 'nullable|sometimes|exists:currencies,id',
            'is_debt' => 'nullable|boolean'
        ]);

        $transaction_exist = Transaction::where('id', $id)->first();
        if (!$transaction_exist) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        // Проверяем права владельца: если не админ, то можно редактировать только свои записи
        if (!$user->is_admin && $transaction_exist->user_id != $userUuid) {
            return response()->json([
                'message' => 'У вас нет прав на редактирование этой транзакции'
            ], 403);
        }

        // Проверяем, не является ли транзакция ограниченной
        if ($this->isRestrictedTransaction($transaction_exist)) {
            $message = $this->getRestrictedTransactionMessage($transaction_exist);
            return response()->json([
                'message' => $message
            ], 403);
        }

        $userHasPermissionToCashRegister = $this->itemsRepository->userHasPermissionToCashRegister($userUuid, $transaction_exist->cash_id);

        if (!$userHasPermissionToCashRegister) {
            return response()->json([
                'message' => 'У вас нет прав на эту кассу'
            ], 403);
        }
        // Поддержка изменения привязки к заказу: маппим order_id -> source_type/source_id
        $updateSourceType = null;
        $updateSourceId = null;
        if ($request->has('order_id')) {
            if ($request->order_id) {
                $updateSourceType = Order::class;
                $updateSourceId = $request->order_id;
            } else {
                // Снять привязку к заказу
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

        // Добавляем сумму и валюту только если они переданы
        if ($request->has('orig_amount')) {
            $updateData['orig_amount'] = $request->orig_amount;
        }
        if ($request->has('currency_id')) {
            $updateData['currency_id'] = $request->currency_id;
        }

        if ($request->has('order_id')) {
            $updateData['source_type'] = $updateSourceType;
            $updateData['source_id'] = $updateSourceId;
        }

        $category_updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления транзакции'
            ], 400);
        }

        // Инвалидируем кэш транзакций
        CacheService::invalidateTransactionsCache();
        // Инвалидируем кэш клиентов (баланс клиента мог измениться)
        if ($request->client_id || $transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        // Инвалидируем кэш проектов (если транзакция привязана к проекту)
        if ($request->project_id || $transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        // Инвалидируем кэш касс (баланс кассы мог измениться)
        CacheService::invalidateCashRegistersCache();

        return response()->json([
            'message' => 'Транзакция обновлена'
        ]);
    }

    public function destroy($id)
    {
        $user = auth('api')->user();
        $userUuid = optional($user)->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $transaction_exist = Transaction::where('id', $id)->first();
        if (!$transaction_exist) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        // Проверяем права владельца: если не админ, то можно удалять только свои записи
        if (!$user->is_admin && $transaction_exist->user_id != $userUuid) {
            return response()->json([
                'message' => 'У вас нет прав на удаление этой транзакции'
            ], 403);
        }

        // Проверяем, не является ли транзакция ограниченной
        if ($this->isRestrictedTransaction($transaction_exist)) {
            $message = $this->getRestrictedTransactionMessage($transaction_exist);
            return response()->json([
                'message' => $message
            ], 403);
        }

        $transaction_deleted = $this->itemsRepository->deleteItem($id);

        if (!$transaction_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления транзакции'
            ], 400);
        }

        // Инвалидируем кэш транзакций
        CacheService::invalidateTransactionsCache();
        // Инвалидируем кэш клиентов (баланс клиента изменился)
        if ($transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        // Инвалидируем кэш проектов (если транзакция была привязана к проекту)
        if ($transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        // Инвалидируем кэш касс (баланс кассы изменился)
        CacheService::invalidateCashRegistersCache();

        return response()->json([
            'message' => 'Транзакция удалена'
        ]);
    }

    public function getTotalByOrderId(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $orderId = $request->query('order_id');
        if (!$orderId) {
            return response()->json(['message' => 'order_id is required'], 400);
        }

        $total = $this->itemsRepository->getTotalByOrderId($userUuid, $orderId);
        return response()->json(['total' => $total]);
    }

    public function show($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $item = $this->itemsRepository->getItemById($id);
        if (!$item) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['item' => $item]);
    }

    private function isRestrictedTransaction($transaction)
    {
        // Нельзя редактировать/удалять транзакции, созданные через:
        // 1. Переводы между кассами
        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return true;
        }

        // 2. Продажи, заказы, складские поступления и другие источники
        // Такие транзакции должны управляться только через свои родительские записи
        if ($transaction->source_type && $transaction->source_id) {
            return true;
        }

        return false;
    }

    private function getRestrictedTransactionMessage($transaction)
    {
        // Проверяем тип источника транзакции
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

    /**
     * Обновить статус долга транзакции (доступно всегда, без ограничения по времени)
     */
    public function updateDebtStatus(Request $request, $id)
    {
        $user = auth('api')->user();
        $userUuid = optional($user)->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'is_debt' => 'required|boolean'
        ]);

        $transaction = Transaction::find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        // Проверяем права владельца: если не админ, то можно редактировать только свои записи
        if (!$user->is_admin && $transaction->user_id != $userUuid) {
            return response()->json([
                'message' => 'У вас нет прав на редактирование этой транзакции'
            ], 403);
        }

        // Проверяем, не является ли транзакция ограниченной
        if ($this->isRestrictedTransaction($transaction)) {
            $message = $this->getRestrictedTransactionMessage($transaction);
            return response()->json([
                'message' => $message
            ], 403);
        }

        $userHasPermissionToCashRegister = $this->itemsRepository->userHasPermissionToCashRegister($userUuid, $transaction->cash_id);

        if (!$userHasPermissionToCashRegister) {
            return response()->json([
                'message' => 'У вас нет прав на эту кассу'
            ], 403);
        }

        // Используем репозиторий для обновления с пересчетом балансов
        $updateData = [
            'client_id' => $transaction->client_id,
            'category_id' => $transaction->category_id,
            'project_id' => $transaction->project_id,
            'date' => $transaction->date,
            'note' => $transaction->note,
            'is_debt' => $request->is_debt
        ];

        $updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$updated) {
            return response()->json([
                'message' => 'Ошибка обновления статуса долга'
            ], 400);
        }

        // Инвалидируем кэш транзакций
        CacheService::invalidateTransactionsCache();

        return response()->json([
            'message' => 'Статус долга обновлён'
        ]);
    }
}
