<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Repositories\UnitRepository;
use App\Services\CacheService;
use App\Support\CompanyScopedPermissions;
use Illuminate\Http\JsonResponse;

class UnitsController extends BaseController
{
    public function __construct(
        private readonly UnitRepository $units,
    ) {
    }

    /**
     * @return JsonResponse
     */
    public function index()
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }
        if (! CompanyScopedPermissions::userHasAny($user, ['units_view', 'units_create', 'units_update'])) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }

        $units = $this->units->getCompanyCatalog($this->getCurrentCompanyId());

        return $this->successResponse(UnitResource::collection($units)->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreUnitRequest $request)
    {
        $this->authorize('create', Unit::class);

        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }
        $unit = $this->units->create([
            'company_id' => $companyId,
            'name' => $request->input('name'),
            'short_name' => $request->input('short_name'),
        ]);

        CacheService::invalidateUnitsCache();

        return $this->successResponse((new UnitResource($unit))->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function update(UpdateUnitRequest $request, int $id)
    {
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }
        $unit = $this->units->findOrFail($id);
        $this->authorize('update', $unit);
        if ($response = $this->guardMutableCompanyUnit($unit, $companyId)) {
            return $response;
        }
        $this->units->update($unit, [
            'name' => $request->input('name'),
            'short_name' => $request->input('short_name'),
        ]);

        $this->invalidateUnitsAndProductSearchCaches();

        return $this->successResponse((new UnitResource($unit))->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function destroy(int $id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }
        if (! CompanyScopedPermissions::userHas($user, 'units_delete')) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }
        $unit = $this->units->findOrFail($id);
        $this->authorize('delete', $unit);
        if ($response = $this->guardMutableCompanyUnit($unit, $companyId)) {
            return $response;
        }
        if ($this->units->isUsedByProducts((int) $unit->id)) {
            return $this->errorResponse(__('units.delete_in_use_by_products'), 422);
        }
        if ($this->units->isUsedByConversions((int) $unit->id)) {
            return $this->errorResponse(__('units.delete_in_use_by_conversions'), 422);
        }
        $this->units->delete($unit);

        $this->invalidateUnitsAndProductSearchCaches();

        return $this->successResponse(['ok' => true]);
    }

    /**
     * @return JsonResponse|null null если единицу можно менять или удалять
     */
    private function guardMutableCompanyUnit(Unit $unit, int $companyId): ?JsonResponse
    {
        if ($unit->company_id !== $companyId) {
            return $this->errorResponse(__('api.common.forbidden'), 403);
        }

        return null;
    }

    private function invalidateUnitsAndProductSearchCaches(): void
    {
        CacheService::invalidateUnitsCache();
        CacheService::invalidateProductsCache();
    }
}
