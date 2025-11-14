<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Project;

class ProjectsController extends Controller
{
    protected $itemsRepository;

    public function __construct(ProjectsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);
        $statusId = $request->input('status_id') ? (int) $request->input('status_id') : null;
        $clientId = $request->input('client_id') ? (int) $request->input('client_id') : null;
        $dateFilter = $request->input('date_filter', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page, null, $dateFilter, $startDate, $endDate, $statusId, $clientId, null);

        return $this->paginatedResponse($items);
    }

    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $activeOnly = $request->input('active_only', false);
        $items = $this->itemsRepository->getAllItems($userUuid, $activeOnly);

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validationRules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        if ($request->has('budget') || $request->has('currency_id') || $request->has('exchange_rate')) {
            $validationRules['budget'] = 'required|numeric';
            $validationRules['currency_id'] = 'nullable|exists:currencies,id';
            $validationRules['exchange_rate'] = 'nullable|numeric|min:0.000001';
        }

        $request->validate($validationRules);

        $itemData = [
            'name' => $request->name,
            'date' => $request->date,
            'user_id' => $userUuid,
            'client_id' => $request->client_id,
            'users' => $request->users,
            'description' => $request->description,
            'status_id' => 1,
        ];

        if ($request->has('budget')) {
            $itemData['budget'] = $request->budget;
        }
        if ($request->has('currency_id')) {
            $itemData['currency_id'] = $request->currency_id;
        }
        if ($request->has('exchange_rate')) {
            $itemData['exchange_rate'] = $request->exchange_rate;
        }

        $item_created = $this->itemsRepository->createItem($itemData);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания проекта', 400);
        }

        CacheService::invalidateProjectsCache();

        return response()->json(['message' => 'Проект создан']);
    }

    public function update(Request $request, $id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $project = Project::find($id);
        if (!$project) {
            return $this->notFoundResponse('Проект не найден');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('projects', 'update', $project)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
        }

        $validationRules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        if ($request->has('budget') || $request->has('currency_id') || $request->has('exchange_rate')) {
            $validationRules['budget'] = 'required|numeric';
            $validationRules['currency_id'] = 'nullable|exists:currencies,id';
            $validationRules['exchange_rate'] = 'nullable|numeric|min:0.000001';
        }

        $request->validate($validationRules);

        $itemData = [
            'name' => $request->name,
            'date' => $request->date,
            'user_id' => $userUuid,
            'client_id' => $request->client_id,
            'users' => $request->users,
            'description' => $request->description,
        ];

        if ($request->has('budget')) {
            $itemData['budget'] = $request->budget;
        }
        if ($request->has('currency_id')) {
            $itemData['currency_id'] = $request->currency_id;
        }
        if ($request->has('exchange_rate')) {
            $itemData['exchange_rate'] = $request->exchange_rate;
        }

        $category_updated = $this->itemsRepository->updateItem($id, $itemData);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления проекта', 400);
        }

        CacheService::invalidateProjectsCache();

        return response()->json(['message' => 'Проект обновлен']);
    }

    public function show($id)
    {
        $project = Project::find($id);
        if (!$project) {
            return $this->notFoundResponse('Проект не найден');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('projects', 'view', $project)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
        }
        try {
            $userId = $this->getAuthenticatedUserIdOrFail();

            $project = $this->itemsRepository->findItemWithRelations($id, $userId);

            if (!$project) {
                return $this->notFoundResponse('Проект не найден или доступ запрещен');
            }


            return response()->json(['item' => $project]);
        } catch (\Exception $e) {
            return $this->errorResponse('Внутренняя ошибка сервера', 500);
        }
    }

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

        try {
            $project = Project::findOrFail($id);

            // Проверяем права с учетом _all/_own
            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
            }

            $storedFiles = $project->files ?? [];

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

            return response()->json(['files' => $storedFiles, 'message' => 'Files uploaded successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при загрузке файлов: Internal server error', 500);
        }
    }

    public function deleteFile(Request $request, $id)
    {
        try {
            $userId = $this->getAuthenticatedUserIdOrFail();

            $project = Project::find($id);
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            // Проверяем права с учетом _all/_own
            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
            }

            $filePath = $request->input('path');
            if (!$filePath) {
                return $this->errorResponse('Путь файла не указан', 400);
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
                return $this->notFoundResponse('Файл не найден в проекте');
            }

            $project->files = $updatedFiles;
            $project->save();


            return response()->json(['files' => $updatedFiles, 'message' => 'Файл успешно удалён']);
        } catch (\Exception $e) {
            return $this->errorResponse('Внутренняя ошибка сервера', 500);
        }
    }

    public function getBalanceHistory(Request $request, $id)
    {
        try {
            $project = Project::findOrFail($id);

            // Проверяем права с учетом _all/_own
            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
            }

            if ($request->has('t')) {
                $this->itemsRepository->invalidateProjectCache($id);
            }

            $history = $this->itemsRepository->getBalanceHistory($id);
            $balance = collect($history)->sum('amount');

            return response()->json([
                'history' => $history,
                'balance' => $balance,
                'budget' => (float) $project->budget,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении истории баланса проекта: ' . $e->getMessage(), 500);
        }
    }

    public function getDetailedBalance($id)
    {
        try {
            $project = Project::findOrFail($id);

            // Проверяем права с учетом _all/_own
            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
            }

            $detailedBalance = $this->itemsRepository->getDetailedBalance($id);

            return response()->json($detailedBalance);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении детального баланса проекта: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $project = Project::find($id);
        if (!$project) {
            return $this->notFoundResponse('Проект не найден');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('projects', 'delete', $project)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого проекта');
        }

        $project = $this->itemsRepository->findItemWithRelations($id, $userUuid);
        if (!$project) {
            return $this->notFoundResponse('Проект не найден или доступ запрещен');
        }

        try {
            $deleted = $this->itemsRepository->deleteItem($id);

            if (!$deleted) {
                return $this->errorResponse('Ошибка удаления проекта', 400);
            }

            CacheService::invalidateProjectsCache();

            return response()->json(['message' => 'Проект удалён']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function batchUpdateStatus(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer|exists:projects,id',
            'status_id' => 'required|integer|exists:project_statuses,id',
        ]);

        // Проверяем права на каждый проект
        $projects = Project::whereIn('id', $request->ids)->get();
        foreach ($projects as $project) {
            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование одного или нескольких проектов');
            }
        }

        try {
            $affected = $this->itemsRepository
                ->updateStatusByIds($request->ids, $request->status_id, $userUuid);

            if ($affected > 0) {
                return response()->json(['message' => "Статус обновлён у {$affected} проект(ов)"]);
            } else {
                return response()->json(['message' => "Статус не изменился"]);
            }
        } catch (\Throwable $th) {
            return $this->errorResponse($th->getMessage() ?: 'Ошибка смены статуса', 400);
        }
    }
}
