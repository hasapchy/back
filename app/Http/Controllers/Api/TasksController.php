<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\TaskRequest;
use App\Repositories\TaskRepository;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TasksController extends BaseController
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
        $data = $request->validated();
        $data['company_id'] = $this->getCurrentCompanyId();

        if (!$data['company_id']) {
            return $this->errorResponse('Company ID is required', 400);
        }

        $task = $this->taskRepository->create($data);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

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
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

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
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

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
     * @deprecated Используйте update с status_id
     */
    public function complete($id)
    {
        // Находим статус "Завершена" по умолчанию
        $completedStatus = \App\Models\TaskStatus::where('name', 'like', '%заверш%')
            ->orWhere('name', 'like', '%completed%')
            ->first();

        if (!$completedStatus) {
            return $this->errorResponse('Статус "Завершена" не найден', 404);
        }

        $task = $this->taskRepository->changeStatus($id, $completedStatus->id);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача отмечена как выполненная'
        ]);
    }

    /**
     * Принять задачу (супервайзером)
     * @deprecated Используйте update с status_id
     */
    public function accept($id)
    {
        // Находим статус "Принята" по умолчанию
        $acceptedStatus = \App\Models\TaskStatus::where('name', 'like', '%принят%')
            ->orWhere('name', 'like', '%accepted%')
            ->first();

        if (!$acceptedStatus) {
            return $this->errorResponse('Статус "Принята" не найден', 404);
        }

        $task = $this->taskRepository->changeStatus($id, $acceptedStatus->id);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача принята'
        ]);
    }

    /**
     * Вернуть задачу на доработку
     * @deprecated Используйте update с status_id
     */
    public function return($id)
    {
        // Находим статус "В работе" по умолчанию
        $inProgressStatus = \App\Models\TaskStatus::where('name', 'like', '%работ%')
            ->orWhere('name', 'like', '%in progress%')
            ->first();

        if (!$inProgressStatus) {
            return $this->errorResponse('Статус "В работе" не найден', 404);
        }

        $task = $this->taskRepository->changeStatus($id, $inProgressStatus->id);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return response()->json([
            'data' => new TaskResource($task),
            'message' => 'Задача возвращена на доработку'
        ]);
    }

    /**
     * Загрузить файлы задачи
     */
    public function uploadFiles(Request $request, $id)
    {
        $request->validate([
            'files.*' => 'required|file|max:10240|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,bmp,svg,zip,rar,7z,txt,md'
        ], [
            'files.*.max' => 'Файл не должен превышать 10MB',
            'files.*.mimes' => 'Неподдерживаемый тип файла'
        ]);

        $files = $request->file('files');

        if (is_null($files)) {
            $files = [];
        } elseif ($files instanceof \Illuminate\Http\UploadedFile) {
            $files = [$files];
        }

        if (count($files) == 0) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $task = $this->taskRepository->findById($id);

            $storedFiles = $task->files ?? [];

            foreach ($files as $file) {
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('tasks/' . $task->id, $filename, 'public');

                $storedFiles[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            }

            $task->update(['files' => $storedFiles]);

            return response()->json(['files' => $storedFiles, 'message' => 'Files uploaded successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ошибка при загрузке файлов: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Удалить файл задачи
     */
    public function deleteFile(Request $request, $id)
    {
        try {
            $task = $this->taskRepository->findById($id);

            $filePath = $request->input('path');
            if (!$filePath) {
                return response()->json(['error' => 'Путь файла не указан'], 400);
            }

            $files = $task->files ?? [];
            $updatedFiles = [];
            $deletedFile = null;

            foreach ($files as $file) {
                if ($file['path'] === $filePath) {
                    $deletedFile = $file;
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    continue;
                }
                $updatedFiles[] = $file;
            }

            if (!$deletedFile) {
                return response()->json(['error' => 'Файл не найден в задаче'], 404);
            }

            $task->files = $updatedFiles;
            $task->save();

            return response()->json(['files' => $updatedFiles, 'message' => 'File deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ошибка при удалении файла: ' . $e->getMessage()], 500);
        }
    }
}
