<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Repositories\LeadsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Лиды
 */
class LeadController extends BaseController
{
    /**
     * @param  LeadsRepository  $itemsRepository
     */
    public function __construct(protected LeadsRepository $itemsRepository)
    {
    }

    /**
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userUuid = (int) $this->getAuthenticatedUserIdOrFail();
        $this->authorize('viewAny', Lead::class);

        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $statusId = $request->input('status_id');
        $statusId = $statusId !== null && $statusId !== '' ? (int) $statusId : null;

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page, $statusId);

        return $this->successResponse([
            'items' => LeadResource::collection($items->items())->resolve(),
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
     * @param  int  $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $this->getAuthenticatedUserIdOrFail();
        $lead = $this->itemsRepository->getItemById((int) $id);
        $this->authorize('view', $lead);

        return $this->successResponse((new LeadResource($lead))->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Компания не выбрана', 422);
        }

        $validated = $request->validated();
        $this->itemsRepository->assertClientBelongsToCompany((int) $validated['client_id'], $companyId);

        $payload = [
            'company_id' => $companyId,
            'creator_id' => (int) $user->id,
            'client_id' => (int) $validated['client_id'],
            'lead_source_id' => $validated['lead_source_id'] ?? null,
            'status_id' => $validated['status_id'] ?? null,
            'comment' => $validated['comment'] ?? null,
        ];
        if (array_key_exists('responsible_id', $validated)) {
            $payload['responsible_id'] = $validated['responsible_id'];
        }

        $lead = $this->itemsRepository->createItem($payload, $user);

        return $this->successResponse((new LeadResource($lead))->resolve(), 'Лид создан');
    }

    /**
     * @return JsonResponse
     */
    public function update(UpdateLeadRequest $request, $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Компания не выбрана', 422);
        }

        $validated = $request->validated();
        if (isset($validated['client_id'])) {
            $this->itemsRepository->assertClientBelongsToCompany((int) $validated['client_id'], $companyId);
        }

        $lead = $this->itemsRepository->updateItem((int) $id, $validated, $user);

        return $this->successResponse((new LeadResource($lead))->resolve(), 'Лид сохранён');
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $lead = $this->itemsRepository->getItemById((int) $id);
        $this->authorize('delete', $lead);
        $this->itemsRepository->deleteItem((int) $id);

        return $this->successResponse(null, 'Лид удалён');
    }
}
