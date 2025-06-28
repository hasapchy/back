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
    // Метод для получения транзакций с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cash_register_id = $request->query('cash_id');
        $date_filter_type = $request->query('date_filter_type');
        $order_id = $request->query('order_id'); // Добавляем параметр order_id

        $items = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            20,
            $cash_register_id,
            $date_filter_type,
            $order_id // Передаем order_id в репозиторий
        );

        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
    }

    // // Метод для получения всех касс
    // public function all(Request $request)
    // {
    //     $userUuid = optional(auth('api')->user())->id;
    //     if (!$userUuid) {
    //         return response()->json(array('message' => 'Unauthorized'), 401);
    //     }
    //     // Получаем склад с пагинацией
    //     $items = $this->itemsRepository->getAllItems($userUuid);

    //     return response()->json($items);
    // }

    // Метод для создания транзакции
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
            'category_id' => 'nullable|sometimes|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date'
        ]);

        // Проверяем права пользователя на кассу
        $userHasPermissionToCashRegister = $this->itemsRepository->userHasPermissionToCashRegister($userUuid, $request->cash_id);

        if (!$userHasPermissionToCashRegister) {
            return response()->json([
                'message' => 'У вас нет прав на эту кассу'
            ], 403);
        }

        // Создаем транзакцию
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
            'date' => $request->date ?? now()
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

    // Метод для обновления транзакции
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        // Валидация данных
        $request->validate([
            'category_id' => 'nullable|sometimes|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date'
        ]);

        $transaction_exist = Transaction::where('id', $id)->first();
        if (!$transaction_exist) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        // Проверяем права пользователя на кассу
        $userHasPermissionToCashRegister = $this->itemsRepository->userHasPermissionToCashRegister($userUuid, $transaction_exist->cash_id);

        if (!$userHasPermissionToCashRegister) {
            return response()->json([
                'message' => 'У вас нет прав на эту кассу'
            ], 403);
        }


        // Обновляем транзакцию
        $category_updated = $this->itemsRepository->updateItem($id, [
            'category_id' => $request->category_id,
            'project_id' => $request->project_id,
            'client_id' => $request->client_id,
            'note' => $request->note,
            'date' => $request->date ?? now()
        ]);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления транзакции'
            ], 400);
        }
        return response()->json([
            'message' => 'Транзакция обновлена'
        ]);
    }

    // Метод для удаления кассы
    // Метод для удаления транзакции
    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Удаляем транзакцию
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
}
