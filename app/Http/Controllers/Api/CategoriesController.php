<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CategoriesRepository;
use Illuminate\Http\Request;

class CategoriesController extends Controller
{
    protected $itemsRepository;

    public function __construct(CategoriesRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    // Метод для получения складов с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $page = $request->input('page', 1);

        // Получаем склад с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, $page);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // Метод для получения всех категорий
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

    // Метод для получения только родительских категорий (первого уровня)
    public function parents(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $items = $this->itemsRepository->getParentCategories($userUuid);

        return response()->json($items);
    }

    // Метод для создания категории
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'parent_id' => 'nullable|exists:categories,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Создаем категорию
        $category_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'parent_id' => $request->parent_id,
            'user_id' => $userUuid,
            'users' => $request->users
        ]);

        if (!$category_created) {
            return response()->json([
                'message' => 'Ошибка создания категории'
            ], 400);
        }
        return response()->json([
            'message' => 'Категория создана'
        ]);
    }

    // Метод для обновления категории
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'parent_id' => 'nullable|exists:categories,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Обновляем категорию
        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'parent_id' => $request->parent_id,
            'user_id' => $userUuid,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления категории'
            ], 400);
        }
        return response()->json([
            'message' => 'Категория обновлена'
        ]);
    }

    // Метод для удаления категории
    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Удаляем категорию
        $category_deleted = $this->itemsRepository->deleteItem($id);

        if (!$category_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления категории'
            ], 400);
        }
        return response()->json([
            'message' => 'Категория удалена'
        ]);
    }
}
