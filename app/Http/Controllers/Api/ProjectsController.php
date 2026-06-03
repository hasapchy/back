<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectReferenceResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Repositories\ProjectsRepository;
use App\Services\CacheService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Контроллер для управления проектами
 */
/**
 * @group Проекты
 */
class ProjectsController extends BaseController
{
    /**
     * @var ProjectsRepository
     */
    protected $itemsRepository;

    public function __construct(
        ProjectsRepository $itemsRepository,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Подготовить данные проекта из запроса
     *
     * @param  Request  $request
     * @param  int  $userId  ID пользователя
     */
    private function prepareProjectData(array $validatedData, int $userId): array
    {
        $data = [
            'name' => $validatedData['name'],
            'creator_id' => $userId,
            'client_id' => $validatedData['client_id'],
            'users' => $validatedData['users'] ?? null,
            'description' => $validatedData['description'] ?? null,
        ];

        if (array_key_exists('date', $validatedData)) {
            $data['date'] = $validatedData['date'];
        }

        if (isset($validatedData['currency_id'])) {
            $data['currency_id'] = $validatedData['currency_id'];
        }

        return $data;
    }

    /**
     * Список проектов
     *
     * @return JsonResponse
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

        $statusCounts = $this->itemsRepository->getStatusCountsForFilters(
            search: is_string($search) ? $search : null,
            dateFilter: (string) $dateFilter,
            startDate: is_string($startDate) ? $startDate : null,
            endDate: is_string($endDate) ? $endDate : null,
            clientId: $clientId,
        );

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                ProjectReferenceResource::class,
                ProjectResource::class,
                $companyId
            ),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'status_counts' => $statusCounts,
            ],
        ]);
    }

    /**
     * Получить все проекты
     *
     * @return JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $activeOnly = (bool) $request->input('active_only', false);
        $items = $this->itemsRepository->getAllItems($activeOnly);
        $companyId = $this->getCurrentCompanyId();
        $class = $this->useReferenceContractsForWave1IndexShow($companyId)
            ? ProjectReferenceResource::class
            : ProjectResource::class;

        return $this->successResponse($class::collection($items)->resolve());
    }

    /**
     * Создать проект
     *
     * @return JsonResponse
     */
    public function store(StoreProjectRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $this->authorize('create', Project::class);

        $validatedData = $request->validated();

        $itemData = $this->prepareProjectData($validatedData, $userUuid);
        $itemData['status_id'] = 1;

        try {
            $itemCreated = $this->itemsRepository->createItem($itemData);

            if (! $itemCreated) {
                return $this->errorResponse(__('api.projects.create_failed'), 400);
            }

            CacheService::invalidateProjectsCache();

            return $this->successResponse(null, __('api.projects.created'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('api.projects.create_failed_prefix').$e->getMessage(), 500);
        }
    }

    /**
     * Обновить проект
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function update(UpdateProjectRequest $request, $id)
    {
        $user = $this->requireAuthenticatedUser();

        $project = Project::findOrFail($id);

        $this->authorize('update', $project);

        $validatedData = $request->validated();

        $itemData = $this->prepareProjectData($validatedData, $user->id);
        unset($itemData['creator_id']);

        try {
            $itemUpdated = $this->itemsRepository->updateItem($id, $itemData);

            if (! $itemUpdated) {
                return $this->errorResponse(__('api.projects.update_failed'), 400);
            }

            CacheService::invalidateProjectsCache();

            return $this->successResponse(null, __('api.projects.updated'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('api.projects.update_failed_prefix').$e->getMessage(), 500);
        }
    }

    /**
     * Получить проект по ID
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function show($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $project = Project::findOrFail($id);

        $this->authorize('view', $project);

        $project = $this->itemsRepository->findItemWithRelations($id);

        if (! $project) {
            return $this->errorResponse(__('api.projects.not_found_or_forbidden'), 404);
        }

        return $this->successResponse(new ProjectResource($project));
    }

    /**
     * Загрузить файлы проекта
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function uploadFiles(Request $request, $id)
    {
        $request->validate([
            'files.*' => 'required|file|max:10240|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,bmp,svg,zip,rar,7z,txt,md',
        ], [
            'files.*.max' => 'Файл не должен превышать 10MB',
            'files.*.mimes' => 'Неподдерживаемый тип файла',
        ]);

        $files = $request->file('files');

        if (is_null($files)) {
            $files = [];
        } elseif ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (count($files) == 0) {
            return $this->errorResponse(__('api.common.no_files_uploaded'), 400);
        }

        if (count($files) > 8) {
            return $this->errorResponse(__('api.tasks.max_files_per_upload'), 400);
        }

        try {
            $project = Project::findOrFail($id);

            $this->authorize('update', $project);

            $storedFiles = $project->files ?? [];
            if (count($storedFiles) + count($files) > 100) {
                return $this->errorResponse(__('api.projects.max_files_total'), 400);
            }

            foreach ($files as $file) {
                $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs('projects/'.$project->id, $filename, 'public');

                $storedFiles[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            }

            $project->update(['files' => $storedFiles]);

            return $this->successResponse($storedFiles, __('api.common.files_uploaded_success'));
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при загрузке файлов: Internal server error'), 500);
        }
    }

    /**
     * Скачать выбранные файлы проекта в архиве
     *
     * @param  int  $id  ID проекта
     * @return BinaryFileResponse|JsonResponse
     */
    public function downloadFiles(Request $request, $id)
    {
        $project = Project::findOrFail($id);

        $this->authorize('view', $project);

        $files = collect($project->files ?? [])
            ->whereIn('path', $request->input('paths', []))
            ->filter(fn ($file) => Storage::disk('public')->exists($file['path']));

        if ($files->isEmpty()) {
            return $this->errorResponse(__('api.projects.files_not_found'), 404);
        }

        $zipPath = storage_path('app/temp/project_'.$project->id.'_'.time().'.zip');
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->errorResponse(__('api.projects.archive_create_failed'), 500);
        }

        foreach ($files as $file) {
            $safeName = basename(str_replace(["\0", '..', '/', '\\'], '', $file['name'] ?? 'file'));
            $safeName = $safeName ?: 'file';
            $zip->addFile(storage_path('app/public/'.$file['path']), $safeName);
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * Удалить файл проекта
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function deleteFile(Request $request, $id)
    {
        try {
            $this->getAuthenticatedUserIdOrFail();

            $project = Project::findOrFail($id);

            $this->authorize('update', $project);

            $filePath = $request->input('path');
            if (! $filePath || str_contains($filePath, '..') || ! str_starts_with($filePath, 'projects/'.$id.'/')) {
                return $this->errorResponse(__('api.tasks.invalid_file_path'), 400);
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

            if (! $deletedFile) {
                return $this->errorResponse(__('api.projects.file_not_found'), 404);
            }

            $project->files = $updatedFiles;
            $project->save();

            return $this->successResponse($updatedFiles, __('api.projects.file_deleted'));
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(__('api.projects.internal_server_error'), 500);
        }
    }

    /**
     * Получить историю баланса проекта
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function getBalanceHistory(Request $request, $id)
    {
        try {
            $project = Project::findOrFail($id);

            $this->authorize('view', $project);

            if ($request->has('t')) {
                $this->itemsRepository->invalidateProjectCache($id);
            }

            $page = $request->input('page') ? max(1, (int) $request->input('page')) : null;
            $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
            $isDebt = $request->input('is_debt');
            $isDebt = is_null($isDebt) ? null : filter_var($isDebt, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $filters = [
                'search' => $request->input('search'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'source' => $request->input('source'),
                'transaction_type' => $request->input('transaction_type'),
                'exclude_debt' => $request->boolean('exclude_debt') ? true : null,
                'is_debt' => $isDebt === true ? true : null,
                'cash_register_id' => $request->input('cash_register_id') ? (int) $request->input('cash_register_id') : null,
            ];
            $result = $this->itemsRepository->getBalanceHistory($id, $page, $perPage, $filters);

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
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->errorResponse(__('api.projects.balance_history_failed_prefix').$e->getMessage(), 500);
        }
    }

    /**
     * Получить детальный баланс проекта
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function getDetailedBalance($id)
    {
        try {
            $project = Project::findOrFail($id);

            $this->authorize('view', $project);

            $detailedBalance = $this->itemsRepository->getDetailedBalance($id);

            return $this->successResponse($detailedBalance);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->errorResponse(__('api.projects.balance_details_failed_prefix').$e->getMessage(), 500);
        }
    }

    /**
     * Удалить проект
     *
     * @param  int  $id  ID проекта
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $this->requireAuthenticatedUser();

        $project = Project::findOrFail($id);

        $this->authorize('delete', $project);

        try {
            $deleted = $this->itemsRepository->deleteItem($id);

            if (! $deleted) {
                return $this->errorResponse(__('api.projects.delete_failed'), 400);
            }

            CacheService::invalidateProjectsCache();

            return $this->successResponse(null, __('api.projects.deleted'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

}
