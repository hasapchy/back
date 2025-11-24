<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Repositories\TaskRepository;
use App\Http\Resources\TaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private TaskRepository $taskRepository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->taskRepository->getFilteredTasks($request);
        return response()->json([
            'data' => TaskResource::collection($tasks),
            'meta' => ['total' => $tasks->total()]
        ]);
    }

    public function store(TaskRequest $request): JsonResponse
    {
        $task = $this->taskRepository->create($request->validated());
        return response()->json(['data' => new TaskResource($task)], 201);
    }

    public function show($id): JsonResponse
    {
        $task = $this->taskRepository->findById($id);
        return response()->json(['data' => new TaskResource($task)]);
    }

    public function update(TaskRequest $request, $id): JsonResponse
    {
        $task = $this->taskRepository->update($id, $request->validated());
        return response()->json(['data' => new TaskResource($task)]);
    }

    public function destroy($id): JsonResponse
    {
        $this->taskRepository->delete($id);
        return response()->json(null, 204);
    }

    // Смена статуса - завершение задачи исполнителем
    public function complete($id): JsonResponse
    {
        $task = $this->taskRepository->changeStatus($id, 'pending');
        return response()->json(['data' => new TaskResource($task)]);
    }

    // Принятие задачи супервайзером
    public function accept($id): JsonResponse
    {
        $task = $this->taskRepository->changeStatus($id, 'completed');
        return response()->json(['data' => new TaskResource($task)]);
    }

    // Возврат задачи на доработку
    public function return($id): JsonResponse
    {
        $task = $this->taskRepository->changeStatus($id, 'in_progress');
        return response()->json(['data' => new TaskResource($task)]);
    }
}
