<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CahRegistersRepository;
use Illuminate\Http\Request;

class CashRegistersController extends Controller
{
    protected $itemsRepository;

    public function __construct(CahRegistersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    // Метод для получения касс с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем склад с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // Метод для получения всех касс
    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем склад с пагинацией
        $items = $this->itemsRepository->getAllItems($userUuid);

        return response()->json($items);
    }

    // Метод для создания кассы
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Создаем категорию
        $item_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'balance' => $request->balance,
            'currency_id' => $request->currency_id,
            'users' => $request->users
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания кассы'
            ], 400);
        }
        return response()->json([
            'message' => 'Касса создана'
        ]);
    }

    // Метод для обновления кассы
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            // 'balance' => 'required|numeric',
            // 'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Обновляем категорию
        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            // 'balance' => $request->balance,
            // 'currency_id' => $request->currency_id,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления кассы'
            ], 400);
        }
        return response()->json([
            'message' => 'Касса обновлена'
        ]);
    }

    // Метод для удаления кассы
    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Удаляем кассу
        $category_deleted = $this->itemsRepository->deleteItem($id);

        if (!$category_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления кассы'
            ], 400);
        }
        return response()->json([
            'message' => 'Категория удалена'
        ]);
    }
}
