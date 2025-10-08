<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Project;

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
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $page = (int) $request->input('page', 1);
        $statusId = $request->input('status_id') ? (int) $request->input('status_id') : null;
        $clientId = $request->input('client_id') ? (int) $request->input('client_id') : null;
        $dateFilter = $request->input('date_filter', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Получаем с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, $page, null, $dateFilter, $startDate, $endDate, $statusId, $clientId, null);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
    }

    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $activeOnly = $request->input('active_only', false);
        $items = $this->itemsRepository->getAllItems($userUuid, $activeOnly);

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $validationRules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        // Добавляем валидацию для полей бюджета только если они переданы
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
            'status_id' => 1, // Устанавливаем статус "Новый" по умолчанию
        ];

        // Добавляем поля бюджета только если они переданы
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
            return response()->json([
                'message' => 'Ошибка создания проекта'
            ], 400);
        }

        // Инвалидируем кэш проектов
        \App\Services\CacheService::invalidateProjectsCache();

        return response()->json([
            'message' => 'Проект создан'
        ]);
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $validationRules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        // Добавляем валидацию для полей бюджета только если они переданы
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

        // Добавляем поля бюджета только если они переданы
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
            return response()->json([
                'message' => 'Ошибка обновления проекта'
            ], 400);
        }

        // Инвалидируем кэш проектов
        \App\Services\CacheService::invalidateProjectsCache();

        return response()->json([
            'message' => 'Проект обновлен'
        ]);
    }

    public function show($id)
    {
        try {
            $userId = optional(auth('api')->user())->id;
            if (!$userId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $project = $this->itemsRepository->findItemWithRelations($id, $userId);

            if (!$project) {
                return response()->json(['error' => 'Проект не найден или доступ запрещен'], 404);
            }


            return response()->json($project);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    public function uploadFiles(Request $request, $id)
    {
        // Валидация файлов
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
            return response()->json(['message' => 'No files uploaded'], 400);
        }

        try {
            $project = Project::findOrFail($id);
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

            return response()->json([
                'message' => 'Files uploaded successfully',
                'files' => $storedFiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при загрузке файлов',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function deleteFile(Request $request, $id)
    {
        try {
            $userId = optional(auth('api')->user())->id;
            if (!$userId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $project = Project::find($id);
            if (!$project) {
                return response()->json(['error' => 'Проект не найден'], 404);
            }

            // Проверяем, имеет ли пользователь доступ к проекту
            if (!$project->hasUser($userId)) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $filePath = $request->input('path');
            if (!$filePath) {
                return response()->json(['error' => 'Путь файла не указан'], 400);
            }

            $files = $project->files ?? [];
            $updatedFiles = [];
            $deletedFile = null;

            foreach ($files as $file) {
                if ($file['path'] === $filePath) {
                    $deletedFile = $file;
                    // Удаляем физический файл
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    continue;
                }
                $updatedFiles[] = $file;
            }

            if (!$deletedFile) {
                return response()->json(['error' => 'Файл не найден в проекте'], 404);
            }

            $project->files = $updatedFiles;
            $project->save();


            return response()->json([
                'message' => 'Файл успешно удалён',
                'files' => $updatedFiles
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    // Получение истории баланса и текущего баланса проекта
    public function getBalanceHistory(Request $request, $id)
    {
        try {
            // Если передан timestamp, принудительно обновляем кэш
            if ($request->has('t')) {
                $this->itemsRepository->invalidateProjectCache($id);
            }

            $history = $this->itemsRepository->getBalanceHistory($id);
            $balance = collect($history)->sum('amount');
            $project = \App\Models\Project::findOrFail($id);

            return response()->json([
                'history' => $history,
                'balance' => $balance,
                'budget' => (float) $project->budget,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при получении истории баланса проекта',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Получение детального баланса проекта (общий, реальный, долговый)
    public function getDetailedBalance($id)
    {
        try {
            $detailedBalance = $this->itemsRepository->getDetailedBalance($id);

            return response()->json($detailedBalance, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при получении детального баланса проекта',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Проверяем, существует ли проект и имеет ли пользователь к нему доступ
        $project = $this->itemsRepository->findItemWithRelations($id, $userUuid);
        if (!$project) {
            return response()->json([
                'message' => 'Проект не найден или доступ запрещен'
            ], 404);
        }

        $deleted = $this->itemsRepository->deleteItem($id);

        if (!$deleted) {
            return response()->json([
                'message' => 'Ошибка удаления проекта'
            ], 400);
        }

        // Инвалидируем кэш проектов
        \App\Services\CacheService::invalidateProjectsCache();

        return response()->json([
            'message' => 'Проект удалён'
        ]);
    }

    public function batchUpdateStatus(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer|exists:projects,id',
            'status_id' => 'required|integer|exists:project_statuses,id',
        ]);

        try {
            $affected = $this->itemsRepository
                ->updateStatusByIds($request->ids, $request->status_id, $userUuid);

            if ($affected > 0) {
                return response()->json([
                    'message' => "Статус обновлён у {$affected} проект(ов)"
                ]);
            } else {
                return response()->json([
                    'message' => "Статус не изменился"
                ]);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage() ?: 'Ошибка смены статуса'
            ], 400);
        }
    }
}
