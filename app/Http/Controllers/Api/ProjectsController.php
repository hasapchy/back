<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchUpdateProjectStatusRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UploadProjectFilesRequest;
use App\Http\Resources\ProjectResource;
use App\Repositories\ProjectsRepository;
use App\Services\CacheService;
use App\Services\ProjectFileService;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use App\Models\Project;

/**
 * Контроллер для управления проектами
 */
class ProjectsController extends Controller
{
    /**
     * @var ProjectsRepository
     */
    protected $itemsRepository;

    /**
     * @var ProjectFileService
     */
    protected $fileService;

    /**
     * @var ProjectService
     */
    protected $projectService;

    /**
     * @param ProjectsRepository $itemsRepository
     * @param ProjectFileService $fileService
     * @param ProjectService $projectService
     */
    public function __construct(ProjectsRepository $itemsRepository, ProjectFileService $fileService, ProjectService $projectService)
    {
        $this->itemsRepository = $itemsRepository;
        $this->fileService = $fileService;
        $this->projectService = $projectService;
    }

    /**
     * Получить список проектов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
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

        return ProjectResource::collection($items)->response();
    }

    /**
     * Получить все проекты
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $activeOnly = (bool) $request->input('active_only', false);
        $items = $this->itemsRepository->getAllItems($userUuid, $activeOnly);

        return ProjectResource::collection($items)->response();
    }

    /**
     * Создать новый проект
     *
     * @param StoreProjectRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProjectRequest $request)
    {
        $user = $this->requireAuthenticatedUser();

        $this->authorize('create', Project::class);

        try {
            $itemData = $this->projectService->prepareProjectData($request, $user);
            $itemData['status_id'] = 1;
            $project = $this->projectService->createProject($itemData, $user);

            CacheService::invalidateProjectsCache();

            $project = Project::with(['client', 'creator', 'currency', 'status', 'users'])->findOrFail($project->id);
            return $this->dataResponse(new ProjectResource($project), 'Проект создан');
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

        $this->authorize('update', $project);

        try {
            $itemData = $this->projectService->prepareProjectData($request, $user);
            $this->projectService->updateProject($project, $itemData, $user);

            CacheService::invalidateProjectsCache();

            $project = Project::with(['client', 'creator', 'currency', 'status', 'users'])->findOrFail($id);
            return $this->dataResponse(new ProjectResource($project), 'Проект обновлен');
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
        try {
            $project = Project::findOrFail($id);

            $this->authorize('view', $project);

            $userId = $this->getAuthenticatedUserIdOrFail();
            $project = Project::with(['client', 'creator', 'currency', 'status', 'users'])->findOrFail($id);

            return new ProjectResource($project);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Загрузить файлы проекта
     *
     * @param UploadProjectFilesRequest $request
     * @param int $id ID проекта
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadFiles(UploadProjectFilesRequest $request, $id)
    {
        try {
            $project = Project::findOrFail($id);

            $this->authorize('update', $project);

            $files = $request->file('files');
            $storedFiles = $this->fileService->uploadFiles($project, $files);

            return $this->dataResponse(['files' => $storedFiles], 'Files uploaded successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при загрузке файлов: ' . $e->getMessage(), 500);
        }
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
            $project = Project::findOrFail($id);

            $this->authorize('update', $project);

            $filePath = $request->input('path');
            if (!$filePath) {
                return $this->errorResponse('Путь файла не указан', 400);
            }

            $updatedFiles = $this->fileService->deleteFile($project, $filePath);

            return $this->dataResponse(['files' => $updatedFiles], 'Файл успешно удалён');
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Файл не найден в проекте') {
                return $this->notFoundResponse($e->getMessage());
            }
            return $this->errorResponse('Внутренняя ошибка сервера: ' . $e->getMessage(), 500);
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

            $this->authorize('view', $project);

            if ($request->has('t')) {
                $this->itemsRepository->invalidateProjectCache($id);
            }

            $history = $this->itemsRepository->getBalanceHistory($id);
            $balance = collect($history)->sum('amount');

            return $this->dataResponse([
                'history' => $history,
                'balance' => $balance,
                'budget' => (float) $project->budget,
            ]);
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

            $this->authorize('view', $project);

            $detailedBalance = $this->itemsRepository->getDetailedBalance($id);

            return $this->dataResponse($detailedBalance);
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
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $project = Project::findOrFail($id);

        $this->authorize('delete', $project);

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

    /**
     * Массовое обновление статуса проектов
     *
     * @param BatchUpdateProjectStatusRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchUpdateStatus(BatchUpdateProjectStatusRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $projects = Project::whereIn('id', $request->ids)->get();
        foreach ($projects as $project) {
            $this->authorize('update', $project);
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
