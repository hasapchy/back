<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreUnitConversionRequest;
use App\Http\Resources\UnitConversionResource;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\CacheService;
use App\Services\UnitConversionGraphService;
use App\Support\CompanyScopedPermissions;
use Illuminate\Http\JsonResponse;

class SettingsUnitConversionsController extends BaseController
{
    /**
     * @return JsonResponse
     */
    public function index()
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }
        if (! CompanyScopedPermissions::userHasAny($user, ['settings_units_view', 'settings_units_manage'])) {
            return $this->errorResponse('Forbidden', 403);
        }
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }

        $items = UnitConversion::query()
            ->where('company_id', $companyId)
            ->with(['parentUnit', 'childUnit'])
            ->orderBy('id')
            ->get();

        return $this->successResponse(UnitConversionResource::collection($items)->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreUnitConversionRequest $request, UnitConversionGraphService $graphService)
    {
        return $this->saveConversion($request, $graphService, null);
    }

    /**
     * @return JsonResponse
     */
    public function update(StoreUnitConversionRequest $request, int $id, UnitConversionGraphService $graphService)
    {
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }
        $row = UnitConversion::query()->where('company_id', $companyId)->whereKey($id)->firstOrFail();

        return $this->saveConversion($request, $graphService, $row);
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
        if (! CompanyScopedPermissions::userHas($user, 'settings_units_manage')) {
            return $this->errorResponse('Forbidden', 403);
        }
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }
        UnitConversion::query()->where('company_id', $companyId)->whereKey($id)->firstOrFail()->delete();

        $this->invalidateConversionCaches();

        return $this->successResponse(['ok' => true]);
    }

    /**
     * @return JsonResponse
     */
    private function saveConversion(StoreUnitConversionRequest $request, UnitConversionGraphService $graphService, ?UnitConversion $row): JsonResponse
    {
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }
        $parentId = $request->integer('parent_unit_id');
        $childId = $request->integer('child_unit_id');
        $qty = $graphService->normalizePackQuantity((string) $request->input('quantity'));
        if ($this->unitsNotVisibleForCompany($parentId, $childId, $companyId)) {
            return $this->errorResponse(__('validation.exists', ['attribute' => 'unit']), 422);
        }

        $graphService->assertConversionAllowed($companyId, $parentId, $childId, $qty, $row?->id);

        $model = $row ?? new UnitConversion;
        $model->company_id = $companyId;
        $model->parent_unit_id = $parentId;
        $model->child_unit_id = $childId;
        $model->quantity = $qty;
        $model->save();

        $this->invalidateConversionCaches();

        return $this->successResponse((new UnitConversionResource($model->load(['parentUnit', 'childUnit'])))->resolve());
    }

    private function invalidateConversionCaches(): void
    {
        CacheService::invalidateUnitsCache();
        CacheService::invalidateProductsCache();
    }

    private function unitsNotVisibleForCompany(int $parentId, int $childId, int $companyId): bool
    {
        return ! $this->unitVisibleForCompany($parentId, $companyId)
            || ! $this->unitVisibleForCompany($childId, $companyId);
    }

    /**
     * @return bool
     */
    private function unitVisibleForCompany(int $unitId, int $companyId): bool
    {
        return Unit::query()
            ->whereKey($unitId)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->exists();
    }

}
