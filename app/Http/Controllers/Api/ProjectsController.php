<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectsRepository;
use Illuminate\Http\Request;

class ProjectsController extends Controller
{
    protected $itemsRepository;

    public function __construct(ProjectsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    // Метод для получения проектов с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
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

    // Метод для получения всех проектов
    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        
        $items = $this->itemsRepository->getAllItems($userUuid);

        return response()->json($items);
    }

    // Метод для создания проекта
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'budget' => 'required|numeric',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Создаем категорию
        $item_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'budget' => $request->budget,
            'date' => $request->date,
            'user_id' => $userUuid,
            'client_id' => $request->client_id,
            'users' => $request->users
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания проекта'
            ], 400);
        }
        return response()->json([
            'message' => 'Проект создан'
        ]);
    }

    // Метод для обновления проекта
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if(!$userUuid){
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'budget' => 'required|numeric',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Обновляем проект
        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'budget' => $request->budget,
            'date' => $request->date,
            'user_id' => $userUuid,
            'client_id' => $request->client_id,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления проекта'
            ], 400);
        }
        return response()->json([
            'message' => 'Проект обновлен'
        ]);
    }

    // // Метод для удаления кассы
    // public function destroy($id)
    // {
    //     $userUuid = optional(auth('api')->user())->id;
    //     if(!$userUuid){
    //         return response()->json(array('message' => 'Unauthorized'), 401);
    //     }
    //     // Удаляем кассу
    //     $category_deleted = $this->itemsRepository->deleteItem($id);

    //     if (!$category_deleted) {
    //         return response()->json([
    //             'message' => 'Ошибка удаления кассы'
    //         ], 400);
    //     }
    //     return response()->json([
    //         'message' => 'Категория удалена'
    //     ]);
    // }
}
