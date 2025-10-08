<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Repositories\TransactionsRepository;
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
            $end_date
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

        $item_created = $this->itemsRepository->createItem([
            'type' => $request->type,
            'user_id' => $userUuid,
            'orig_amount' => $request->orig_amount,
            'currency_id' => $request->currency_id,
            'cash_id' => $request->cash_id,
            'category_id' => $request->category_id,
            'project_id' => $request->project_id,
            'client_id' => $request->client_id,
            'order_id' => $request->order_id,
            'note' => $request->note,
            'date' => $request->date ?? now(),
            'is_debt' => $request->is_debt ?? false
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания транзакции'
            ], 400);
        }
        return response()->json([
            'message' => 'Транзакция создана'
        ]);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
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

        // Проверяем, не является ли транзакция ограниченной
        if ($this->isRestrictedTransaction($transaction_exist)) {
            return response()->json([
                'message' => 'Нельзя редактировать эту транзакцию'
            ], 403);
        }

        $userHasPermissionToCashRegister = $this->itemsRepository->userHasPermissionToCashRegister($userUuid, $transaction_exist->cash_id);

        if (!$userHasPermissionToCashRegister) {
            return response()->json([
                'message' => 'У вас нет прав на эту кассу'
            ], 403);
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

        $category_updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления транзакции'
            ], 400);
        }
        return response()->json([
            'message' => 'Транзакция обновлена'
        ]);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $transaction_exist = Transaction::where('id', $id)->first();
        if (!$transaction_exist) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        // Проверяем, не является ли транзакция ограниченной
        if ($this->isRestrictedTransaction($transaction_exist)) {
            return response()->json([
                'message' => 'Нельзя удалить эту транзакцию'
            ], 403);
        }

        $transaction_deleted = $this->itemsRepository->deleteItem($id);

        if (!$transaction_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления транзакции'
            ], 400);
        }

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
        return $transaction->cashTransfersFrom()->exists() ||
            $transaction->cashTransfersTo()->exists();
    }
}
