<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeadSourceResource;
use App\Repositories\LeadSourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Лиды
 * @subgroup Источники лидов
 */
class LeadSourceController extends BaseController
{
    /**
     * @param  LeadSourceRepository  $itemsRepository
     */
    public function __construct(protected LeadSourceRepository $itemsRepository)
    {
    }

    /**
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $this->getAuthenticatedUserIdOrFail();
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page);

        return $this->successResponse([
            'items' => LeadSourceResource::collection($items->items())->resolve(),
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
        $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems();

        return $this->successResponse(LeadSourceResource::collection($items)->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Компания не выбрана', 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $created = $this->itemsRepository->createItem([
            'company_id' => $companyId,
            'creator_id' => (int) $user->id,
            'name' => $validated['name'],
        ]);

        return $this->successResponse((new LeadSourceResource($created))->resolve(), 'Источник создан');
    }

    /**
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $updated = $this->itemsRepository->updateItem((int) $id, [
            'name' => $validated['name'],
        ]);

        return $this->successResponse((new LeadSourceResource($updated))->resolve(), 'Источник обновлён');
    }

    /**
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $this->itemsRepository->deleteItem((int) $id);

        return $this->successResponse(null, 'Источник удалён');
    }
}
