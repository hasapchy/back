<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTaskStatusRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Models\TaskStatus;
use App\Repositories\TaskStatusRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со статусами задач
 */
class TaskStatusController extends BaseController
{
    protected $taskStatusRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(TaskStatusRepository $taskStatusRepository)
    {
        $this->taskStatusRepository = $taskStatusRepository;
    }

    /**
     * Получить список статусов задач с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $items = $this->taskStatusRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все статусы задач
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->taskStatusRepository->getAllItems($userUuid);

        return response()->json($items);
    }

    /**
     * Создать новый статус задачи
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTaskStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->taskStatusRepository->createItem([
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'user_id' => $userUuid,
        ]);
        if (! $created) {
            return $this->errorResponse('Ошибка создания статуса', 400);
        }

        return response()->json(['message' => 'Статус создан']);
    }

    /**
     * Обновить статус задачи
     *
     * @param  Request  $request
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTaskStatusRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $updated = $this->taskStatusRepository->updateItem($id, [
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
        ]);
        if (! $updated) {
            return $this->errorResponse('Ошибка обновления статуса', 400);
        }

        return response()->json(['message' => 'Статус обновлен']);
    }

    /**
     * Удалить статус задачи
     *
     * @param  int  $id  ID статуса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $protectedIds = [1, 2, 3, 4];
        if (in_array($id, $protectedIds)) {
            return $this->errorResponse('Системный статус нельзя удалить', 400);
        }

        $status = TaskStatus::findOrFail($id);
        if ($status->tasks()->count() > 0) {
            return $this->errorResponse('Нельзя удалить статус, который используется в задачах', 400);
        }

        $deleted = $this->taskStatusRepository->deleteItem($id);
        if (! $deleted) {
            return $this->errorResponse('Ошибка удаления статуса', 400);
        }

        return response()->json(['message' => 'Статус удален']);
    }
}
