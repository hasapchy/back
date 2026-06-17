<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\TaskRequest;
use App\Repositories\TaskRepository;
use App\Http\Resources\TaskReferenceResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @group Задачи
 */
class TasksController extends BaseController
{
    protected $itemsRepository;

    public function __construct(
        TaskRepository $itemsRepository,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список задач
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Task::class);

        $tasks = $this->itemsRepository->getFilteredTasks($request);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $tasks->items(),
                TaskReferenceResource::class,
                TaskResource::class,
                $companyId
            ),
            'meta' => $this->paginationMeta($tasks),
        ]);
    }

    /**
     * Просроченные задачи
     *
     * @hideFromAPIDocumentation
     */
    public function overdueCount()
    {
        $user = $this->requireAuthenticatedUser();
        $permissions = $this->getUserPermissions($user);
        if (!in_array('tasks_view_all', $permissions) && !in_array('tasks_view_own', $permissions)) {
            return $this->errorResponse(__('api.tasks.view_forbidden'), 403);
        }

        $count = $this->itemsRepository->getOverdueCount();

        return $this->successResponse(['count' => $count]);
    }

    /**
     * Создать задачу
     */
    public function store(TaskRequest $request)
    {
        $this->authorize('create', Task::class);

        $data = $request->validated();
        $data['company_id'] = $this->getCurrentCompanyId();

        if (!$data['company_id']) {
            return $this->errorResponse(__('api.common.company_id_required'), 400);
        }

        $task = $this->itemsRepository->create($data);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $data['company_id'];
        if ($companyId > 0) {
            $this->inAppNotificationDispatcher->dispatch(
                $companyId,
                'tasks_new',
                (int) $user->id,
                'Новая задача',
                $task->title,
                ['route' => '/tasks/'.$task->id, 'task_id' => $task->id]
            );
        }

        return $this->successResponse([
            'data' => new TaskResource($task),
            'message' => 'Задача успешно создана'
        ], null, 201);
    }

    /**
     * Получить задачу по ID
     */
    public function show($id)
    {
        $task = $this->itemsRepository->findById($id);
        $this->authorize('view', $task);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return $this->successResponse([
            'data' => new TaskResource($task)
        ]);
    }

    /**
     * Обновить задачу
     */
    public function update(TaskRequest $request, $id)
    {
        $this->authorize('update', $this->itemsRepository->findById($id));

        $task = $this->itemsRepository->update($id, $request->validated());
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return $this->successResponse([
            'data' => new TaskResource($task),
            'message' => 'Задача успешно обновлена'
        ]);
    }

    /**
     * Удалить задачу
     */
    public function destroy($id)
    {
        $this->authorize('delete', $this->itemsRepository->findById($id));

        $this->itemsRepository->delete($id);

        return $this->successResponse([
            'message' => 'Задача успешно удалена'
        ], null, 204);
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
            return $this->errorResponse(__('Статус "Завершена" не найден'), 404);
        }

        $task = $this->itemsRepository->changeStatus($id, $completedStatus->id);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return $this->successResponse([
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
            return $this->errorResponse(__('Статус "Принята" не найден'), 404);
        }

        $task = $this->itemsRepository->changeStatus($id, $acceptedStatus->id);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return $this->successResponse([
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
            return $this->errorResponse(__('Статус "В работе" не найден'), 404);
        }

        $task = $this->itemsRepository->changeStatus($id, $inProgressStatus->id);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        return $this->successResponse([
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
            return $this->errorResponse(__('api.common.no_files_uploaded'), 400);
        }

        if (count($files) > 8) {
            return $this->errorResponse(__('api.tasks.max_files_per_upload'), 400);
        }

        try {
            $task = $this->itemsRepository->findById($id);

            $storedFiles = $task->files ?? [];
            if (count($storedFiles) + count($files) > 50) {
                return $this->errorResponse(__('api.tasks.max_files_total'), 400);
            }

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

            return $this->successResponse($storedFiles, __('api.common.files_uploaded_success'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('api.tasks.upload_failed_prefix') . $e->getMessage(), 500);
        }
    }

    /**
     * Удалить файл задачи
     */
    public function deleteFile(Request $request, $id)
    {
        try {
            $task = $this->itemsRepository->findById($id);

            $filePath = $request->input('path');
            if (!$filePath || str_contains($filePath, '..') || !str_starts_with($filePath, 'tasks/' . $id . '/')) {
                return $this->errorResponse(__('api.tasks.invalid_file_path'), 400);
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
                return $this->errorResponse(__('api.tasks.file_not_found'), 404);
            }

            $task->files = $updatedFiles;
            $task->save();

            return $this->successResponse($updatedFiles, __('api.common.file_deleted_success'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('api.tasks.delete_file_failed_prefix') . $e->getMessage(), 500);
        }
    }
}
