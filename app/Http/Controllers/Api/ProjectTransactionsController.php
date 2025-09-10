<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectTransaction;
use App\Repositories\ProjectTransactionsRepository;
use Illuminate\Http\Request;

class ProjectTransactionsController extends Controller
{
    protected $itemsRepository;

    public function __construct(ProjectTransactionsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $search = $request->query('search');
        $dateFilter = $request->query('date_filter', 'all_time');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $items = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            20,
            $search,
            $dateFilter,
            $startDate,
            $endDate
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
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Валидация данных
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'note' => 'nullable|string|max:1000',
            'date' => 'nullable|date'
        ]);

        $item_created = $this->itemsRepository->createItem([
            'user_id' => $userUuid,
            'amount' => $request->amount,
            'currency_id' => $request->currency_id,
            'note' => $request->note,
            'date' => $request->date ?? now()
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания прихода'
            ], 400);
        }

        return response()->json([
            'message' => 'Приход создан'
        ]);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'currency_id' => 'nullable|exists:currencies,id',
            'note' => 'nullable|string|max:1000',
            'date' => 'nullable|date'
        ]);

        $transaction_exist = ProjectTransaction::where('id', $id)->where('user_id', $userUuid)->first();
        if (!$transaction_exist) {
            return response()->json(['message' => 'Приход не найден'], 404);
        }

        $updateData = [];
        if ($request->has('amount')) {
            $updateData['amount'] = $request->amount;
        }
        if ($request->has('currency_id')) {
            $updateData['currency_id'] = $request->currency_id;
        }
        if ($request->has('note')) {
            $updateData['note'] = $request->note;
        }
        if ($request->has('date')) {
            $updateData['date'] = $request->date;
        }

        $item_updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$item_updated) {
            return response()->json([
                'message' => 'Ошибка обновления прихода'
            ], 400);
        }

        return response()->json([
            'message' => 'Приход обновлен'
        ]);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $transaction_exist = ProjectTransaction::where('id', $id)->where('user_id', $userUuid)->first();
        if (!$transaction_exist) {
            return response()->json(['message' => 'Приход не найден'], 404);
        }

        $transaction_deleted = $this->itemsRepository->deleteItem($id);

        if (!$transaction_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления прихода'
            ], 400);
        }

        return response()->json([
            'message' => 'Приход удален'
        ]);
    }

    public function show($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $item = $this->itemsRepository->getItemById($id);
        if (!$item || $item->user_id !== $userUuid) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['item' => $item]);
    }

    public function getTotalAmount(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $dateFilter = $request->query('date_filter', 'all_time');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $total = $this->itemsRepository->getTotalAmount($userUuid, $dateFilter, $startDate, $endDate);

        return response()->json(['total' => $total]);
    }
}
