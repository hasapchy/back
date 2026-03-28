<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Repositories\ProjectsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Project;
use ZipArchive;

/**
 * Контроллер для управления проектами
 */
class ProjectsController extends BaseController
{
    /**
     * @var ProjectsRepository
     */
    protected $itemsRepository;

    /**
     * @param ProjectsRepository $itemsRepository
     */
    public function __construct(ProjectsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить правила валидации для проекта
     *
     * @param Request $request
     * @return array
     */
    private function getValidationRules(Request $request): array
    {
        $rules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'nullable|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        if ($request->has('budget') || $request->has('currency_id')) {
            $rules['budget'] = 'required|numeric';
            $rules['currency_id'] = 'nullable|exists:currencies,id';
        }

        return $rules;
    }

    /**
     * Подготовить данные проекта из запроса
     *
     * @param Request $request
     * @param int $userId ID пользователя
     * @return array
     */
    private function prepareProjectData(array $validatedData, int $userId): array
    {
        $data = [
            'name' => $validatedData['name'],
            'date' => $validatedData['date'] ?? null,
            'creator_id' => $userId,
            'client_id' => $validatedData['client_id'],
            'users' => $validatedData['users'] ?? null,
            'description' => $validatedData['description'] ?? null,
        ];

        if (isset($validatedData['budget'])) {
            $data['budget'] = $validatedData['budget'];
        }
        if (isset($validatedData['currency_id'])) {
            $data['currency_id'] = $validatedData['currency_id'];
        }

        return $data;
    }

    /**
     * Получить список проектов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');
        $statusId = $request->input('status_id') ? (int) $request->input('status_id') : null;
        $clientId = $request->input('client_id') ? (int) $request->input('client_id') : null;
        $dateFilter = $request->input('date_filter', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page, $search, $dateFilter, $startDate, $endDate, $statusId, $clientId, null);

        return $this->successResponse([
            'items' => ProjectResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Получить все проекты
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $activeOnly = (bool) $request->input('active_only', false);
        $items = $this->itemsRepository->getAllItems($activeOnly);

        return $this->successResponse(ProjectResource::collection($items)->resolve());
    }

    /**
     * Создать новый проект
     *
     * @param StoreProjectRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProjectRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        if (!$this->hasPermission('projects_create')) {
            return $this->errorResponse('У вас нет прав на создание проектов', 403);
        }

        $validatedData = $request->validated();

        $itemData = $this->prepareProjectData($validatedData, $userUuid);
        $itemData['status_id'] = 1;

        try {
            $itemCreated = $this->itemsRepository->createItem($itemData);

            if (!$itemCreated) {
                return $this->errorResponse('Ошибка создания проекта', 400);
            }

            CacheService::invalidateProjectsCache();

            return $this->successResponse(null, 'Проект создан');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка создания проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Обновить проект
     *
     * @param UpdateProjectRequest $request
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProjectRequest $request, $id)
    {
        $user = $this->requireAuthenticatedUser();

        $project = Project::findOrFail($id);

        if (!$this->canPerformAction('projects', 'update', $project)) {
            return $this->errorResponse('У вас нет прав на редактирование этого проекта', 403);
        }

        $validatedData = $request->validated();

        $itemData = $this->prepareProjectData($validatedData, $user->id);
        unset($itemData['creator_id']);

        try {
            $itemUpdated = $this->itemsRepository->updateItem($id, $itemData);

            if (!$itemUpdated) {
                return $this->errorResponse('Ошибка обновления проекта', 400);
            }

            CacheService::invalidateProjectsCache();

            return $this->successResponse(null, 'Проект обновлен');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка обновления проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить проект по ID
     *
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $this->getAuthenticatedUserIdOrFail();
        
        $project = Project::findOrFail($id);

        if (!$this->canPerformAction('projects', 'view', $project)) {
            return $this->errorResponse('У вас нет прав на просмотр этого проекта', 403);
        }

        $project = $this->itemsRepository->findItemWithRelations($id);

        if (!$project) {
            return $this->errorResponse('Проект не найден или доступ запрещен', 404);
        }

        return $this->successResponse(new ProjectResource($project));
    }

    /**
     * Загрузить файлы проекта
     *
     * @param Request $request
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
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
            return $this->errorResponse('No files uploaded', 400);
        }

        if (count($files) > 8) {
            return $this->errorResponse('Максимум 8 файлов за раз', 400);
        }

        try {
            $project = Project::findOrFail($id);

            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->errorResponse('У вас нет прав на редактирование этого проекта', 403);
            }

            $storedFiles = $project->files ?? [];
            if (count($storedFiles) + count($files) > 100) {
                return $this->errorResponse('Максимум 100 файлов в проекте', 400);
            }

            foreach ($files as $file) {
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('projects/' . $project->id, $filename, 'public');

                $storedFiles[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            }

            $project->update(['files' => $storedFiles]);

            return $this->successResponse($storedFiles, 'Files uploaded successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при загрузке файлов: Internal server error', 500);
        }
    }

    /**
     * Скачать выбранные файлы проекта в архиве
     *
     * @param Request $request
     * @param int $id ID проекта
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function downloadFiles(Request $request, $id)
    {
        $project = Project::findOrFail($id);

        if (!$this->canPerformAction('projects', 'view', $project)) {
            return $this->errorResponse('У вас нет прав на просмотр этого проекта', 403);
        }

        $files = collect($project->files ?? [])
            ->whereIn('path', $request->input('paths', []))
            ->filter(fn($file) => Storage::disk('public')->exists($file['path']));

        if ($files->isEmpty()) {
            return $this->errorResponse('Файлы не найдены', 404);
        }

        $zipPath = storage_path('app/temp/project_' . $project->id . '_' . time() . '.zip');
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->errorResponse('Не удалось создать архив', 500);
        }

        foreach ($files as $file) {
            $safeName = basename(str_replace(["\0", '..', '/', '\\'], '', $file['name'] ?? 'file'));
            $safeName = $safeName ?: 'file';
            $zip->addFile(storage_path('app/public/' . $file['path']), $safeName);
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * Удалить файл проекта
     *
     * @param Request $request
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteFile(Request $request, $id)
    {
        try {
            $this->getAuthenticatedUserIdOrFail();

            $project = Project::findOrFail($id);

            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->errorResponse('У вас нет прав на редактирование этого проекта', 403);
            }

            $filePath = $request->input('path');
            if (!$filePath || str_contains($filePath, '..') || !str_starts_with($filePath, 'projects/' . $id . '/')) {
                return $this->errorResponse('Некорректный путь файла', 400);
            }

            $files = $project->files ?? [];
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
                return $this->errorResponse('Файл не найден в проекте', 404);
            }

            $project->files = $updatedFiles;
            $project->save();


            return $this->successResponse($updatedFiles, 'Файл успешно удалён');
        } catch (\Exception $e) {
            return $this->errorResponse('Внутренняя ошибка сервера', 500);
        }
    }

    /**
     * Получить историю баланса проекта
     *
     * @param Request $request
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalanceHistory(Request $request, $id)
    {
        try {
            $project = Project::findOrFail($id);

            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->errorResponse('У вас нет прав на просмотр этого проекта', 403);
            }

            if ($request->has('t')) {
                $this->itemsRepository->invalidateProjectCache($id);
            }

            $page = $request->input('page') ? max(1, (int) $request->input('page')) : null;
            $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
            $result = $this->itemsRepository->getBalanceHistory($id, $page, $perPage);

            $balance = $this->itemsRepository->getTotalBalance($id);
            $response = [
                'balance' => $balance,
                'budget' => (float) $project->budget,
            ];
            if (isset($result['history'])) {
                $response['history'] = $result['history'];
                $response['current_page'] = $result['current_page'];
                $response['last_page'] = $result['last_page'];
                $response['total'] = $result['total'];
                $response['per_page'] = $result['per_page'];
            } else {
                $response['history'] = $result;
            }

            return $this->successResponse($response);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении истории баланса проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить детальный баланс проекта
     *
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetailedBalance($id)
    {
        try {
            $project = Project::findOrFail($id);

            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->errorResponse('У вас нет прав на просмотр этого проекта', 403);
            }

            $detailedBalance = $this->itemsRepository->getDetailedBalance($id);

            return $this->successResponse($detailedBalance);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении детального баланса проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Удалить проект
     *
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->requireAuthenticatedUser();

        $project = Project::findOrFail($id);

        if (!$this->canPerformAction('projects', 'delete', $project)) {
            return $this->errorResponse('У вас нет прав на удаление этого проекта', 403);
        }

        try {
            $deleted = $this->itemsRepository->deleteItem($id);

            if (!$deleted) {
                return $this->errorResponse('Ошибка удаления проекта', 400);
            }

            CacheService::invalidateProjectsCache();

            return $this->successResponse(null, 'Проект удалён');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Массовое обновление статуса проектов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchUpdateStatus(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer|exists:projects,id',
            'status_id' => 'required|integer|exists:project_statuses,id',
        ]);

        $projects = Project::whereIn('id', $request->ids)->get();
        foreach ($projects as $project) {
            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->errorResponse('У вас нет прав на редактирование одного или нескольких проектов', 403);
            }
        }

        try {
            $affected = $this->itemsRepository
                ->updateStatusByIds($request->ids, $request->status_id, $userUuid);

            if ($affected > 0) {
                return $this->successResponse(null, "Статус обновлён у {$affected} проект(ов)");
            } else {
                return $this->successResponse(null, "Статус не изменился");
            }
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage() ?: 'Ошибка смены статуса', 400);
        }
    }
}
