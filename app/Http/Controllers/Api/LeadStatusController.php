<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreLeadStatusRequest;
use App\Http\Requests\UpdateLeadStatusRequest;
use App\Http\Resources\LeadStatusResource;
use App\Repositories\LeadStatusRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Лиды
 * @subgroup Статусы лидов
 */
class LeadStatusController extends BaseController
{
    /**
     * @param  LeadStatusRepository  $itemsRepository
     */
    public function __construct(protected LeadStatusRepository $itemsRepository)
    {
    }

    /**
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userUuid = (int) $this->getAuthenticatedUserIdOrFail();
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => LeadStatusResource::collection($items->items())->resolve(),
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
     * @return JsonResponse
     */
    public function all(Request $request): JsonResponse
    {
        $userUuid = (int) $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems($userUuid);

        return $this->successResponse(LeadStatusResource::collection($items)->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreLeadStatusRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Компания не выбрана', 422);
        }

        $validated = $request->validated();
        $created = $this->itemsRepository->createItem([
            'company_id' => $companyId,
            'creator_id' => (int) $user->id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#6c757d',
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort' => (int) ($validated['sort'] ?? 0),
            'kanban_outcome' => $validated['kanban_outcome'] ?? null,
        ]);

        return $this->successResponse((new LeadStatusResource($created))->resolve(), 'Статус создан');
    }

    /**
     * @return JsonResponse
     */
    public function update(UpdateLeadStatusRequest $request, $id): JsonResponse
    {
        $validated = $request->validated();
        $payload = [
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#6c757d',
        ];
        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }
        if (array_key_exists('sort', $validated)) {
            $payload['sort'] = (int) $validated['sort'];
        }
        if (array_key_exists('kanban_outcome', $validated)) {
            $payload['kanban_outcome'] = $validated['kanban_outcome'];
        }
        $updated = $this->itemsRepository->updateItem((int) $id, $payload);

        return $this->successResponse((new LeadStatusResource($updated))->resolve(), 'Статус обновлён');
    }

    /**
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $this->itemsRepository->deleteItem((int) $id);

        return $this->successResponse(null, 'Статус удалён');
    }
}
