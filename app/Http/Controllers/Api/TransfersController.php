<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TransactionsRepository;
use App\Repositories\TransfersRepository;
use Illuminate\Http\Request;

class TransfersController extends Controller
{
    protected $itemsRepository;

    public function __construct(TransfersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    // Метод для получения трансферов с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // // Метод для получения всех проектов
    // public function all(Request $request)
    // {
    //     $userUuid = optional(auth('api')->user())->id;
    //     if(!$userUuid){
    //         return response()->json(array('message' => 'Unauthorized'), 401);
    //     }

    //     $items = $this->itemsRepository->getAllItems($userUuid);

    //     return response()->json($items);
    // }

    // Метод для создания
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'cash_id_from' => 'required|exists:cash_registers,id',
            'cash_id_to' => 'required|exists:cash_registers,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|sometimes|string'
        ]);
        // Проверяем права пользователя на кассу
        $transactions_repository = new TransactionsRepository();

        $userHasPermissionToCashRegisters =
            $transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_from) &&
            $transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_to);

        if (!$userHasPermissionToCashRegisters) {
            return response()->json([
                'message' => 'У вас нет прав на одну или несколько касс'
            ], 403);
        }

        // Создаем трансфер
        $item_created = $this->itemsRepository->createItem([
            'cash_id_from' => $request->cash_id_from,
            'cash_id_to' => $request->cash_id_to,
            'amount' => $request->amount,
            'user_id' => $userUuid,
            'note' => $request->note
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания трансфера'
            ], 400);
        }
        return response()->json([
            'message' => 'Трансфер создан'
        ]);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'cash_id_from' => 'required|exists:cash_registers,id',
            'cash_id_to' => 'required|exists:cash_registers,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string'
        ]);

        $transactions_repository = new TransactionsRepository();

        if (
            !$transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_from) ||
            !$transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_to)
        ) {
            return response()->json(['message' => 'Нет прав на кассы'], 403);
        }

        $updated = $this->itemsRepository->updateItem($id, [
            'cash_id_from' => $request->cash_id_from,
            'cash_id_to' => $request->cash_id_to,
            'amount' => $request->amount,
            'note' => $request->note,
            'user_id' => $userUuid,
        ]);

        if (!$updated) {
            return response()->json(['message' => 'Ошибка обновления'], 400);
        }

        return response()->json(['message' => 'Трансфер обновлён']);
    }


    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $deleted = $this->itemsRepository->deleteItem($id);

        if (!$deleted) {
            return response()->json(['message' => 'Ошибка удаления'], 400);
        }

        return response()->json(['message' => 'Трансфер удалён']);
    }
}
