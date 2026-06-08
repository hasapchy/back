<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectReferenceResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Http\Resources\Chat\ChatResource;
use App\Repositories\ProjectsRepository;
use App\Services\CacheService;
use App\Services\Chat\ChatService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        protected ChatService $chatService,
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
     * Открыть или создать чат проекта
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function ensureChat(int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $project = Project::query()->findOrFail($id);

        if ((int) $project->company_id !== $companyId) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }

        $chat = $this->chatService->ensureProjectChat($companyId, $project, $user);

        return (new ChatResource($chat))->response()->setStatusCode(200);
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
