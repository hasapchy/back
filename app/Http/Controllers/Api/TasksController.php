<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Repositories\TaskRepository;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;

class TasksController extends Controller
{
    protected $taskRepository;

    public function __construct(TaskRepository $taskRepository)
    {
        $this->taskRepository = $taskRepository;
    }

    /**
     * Получить список задач с пагинацией
     */
    public function index(Request $request)
    {
        $tasks = $this->taskRepository->getFilteredTasks($request);

        return response()->json([
            'data' => TaskResource::collection($tasks->items()),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ]
        ]);
    }

    /**
     * Создать новую задачу
     */
    public function store(TaskRequest $request)
    {
        $task = $this->taskRepository->create($request->validated());
        $task->load(['creator', 'supervisor', 'executor', 'project']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача успешно создана'
        ], 201);
    }

    /**
     * Получить задачу по ID
     */
    public function show($id)
    {
        $task = $this->taskRepository->findById($id);
        $task->load(['creator', 'supervisor', 'executor', 'project']);

        return response()->json([
            'data' => new TaskResource($task)
        ]);
    }

    /**
     * Обновить задачу
     */
    public function update(TaskRequest $request, $id)
    {
        $task = $this->taskRepository->update($id, $request->validated());
        $task->load(['creator', 'supervisor', 'executor', 'project']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача успешно обновлена'
        ]);
    }

    /**
     * Удалить задачу
     */
    public function destroy($id)
    {
        $this->taskRepository->delete($id);

        return response()->json([
            'message' => 'Задача успешно удалена'
        ], 204);
    }

    /**
     * Завершить задачу (исполнителем)
     */
    public function complete($id)
    {
        $task = $this->taskRepository->changeStatus($id, Task::STATUS_COMPLETED);
        $task->load(['creator', 'supervisor', 'executor', 'project']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача отмечена как выполненная'
        ]);
    }

    /**
     * Принять задачу (супервайзером)
     */
    public function accept($id)
    {
        $task = $this->taskRepository->changeStatus($id, Task::STATUS_COMPLETED);
        $task->load(['creator', 'supervisor', 'executor', 'project']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача принята'
        ]);
    }

    /**
     * Вернуть задачу на доработку
     */
    public function return($id)
    {
        $task = $this->taskRepository->changeStatus($id, Task::STATUS_IN_PROGRESS);
        $task->load(['creator', 'supervisor', 'executor', 'project']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача возвращена на доработку'
        ]);
    }
}
