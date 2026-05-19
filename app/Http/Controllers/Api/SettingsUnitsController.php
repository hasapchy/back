<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use App\Services\CacheService;
use App\Support\CompanyScopedPermissions;
use Illuminate\Http\JsonResponse;

class SettingsUnitsController extends BaseController
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
        if (! CompanyScopedPermissions::userHasAny($user, ['settings_units_view', 'settings_units_create', 'settings_units_edit'])) {
            return $this->errorResponse('Forbidden', 403);
        }

        $units = Unit::forCompanyCatalog($this->getCurrentCompanyId())->get();

        return $this->successResponse(UnitResource::collection($units)->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreUnitRequest $request)
    {
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }
        $unit = new Unit;
        $unit->company_id = $companyId;
        $unit->name = $request->input('name');
        $unit->short_name = $request->input('short_name');
        $unit->save();

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
            return $this->errorResponse('Forbidden', 403);
        }
        $unit = Unit::query()->whereKey($id)->firstOrFail();
        if ($response = $this->guardMutableCompanyUnit($unit, $companyId)) {
            return $response;
        }
        $unit->name = $request->input('name');
        $unit->short_name = $request->input('short_name');
        $unit->save();

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
        if (! CompanyScopedPermissions::userHas($user, 'settings_units_edit')) {
            return $this->errorResponse('Forbidden', 403);
        }
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }
        $unit = Unit::query()->whereKey($id)->firstOrFail();
        if ($response = $this->guardMutableCompanyUnit($unit, $companyId)) {
            return $response;
        }
        if (Product::query()->where('unit_id', $unit->id)->exists()) {
            return $this->errorResponse(__('units.delete_in_use_by_products'), 422);
        }
        if (ProductUnitConversion::query()->where(function ($q) use ($unit) {
            $q->where('parent_unit_id', $unit->id)->orWhere('child_unit_id', $unit->id);
        })->exists()) {
            return $this->errorResponse(__('units.delete_in_use_by_conversions'), 422);
        }
        $unit->delete();

        $this->invalidateUnitsAndProductSearchCaches();

        return $this->successResponse(['ok' => true]);
    }

    /**
     * @return JsonResponse|null null если единицу можно менять или удалять
     */
    private function guardMutableCompanyUnit(Unit $unit, int $companyId): ?JsonResponse
    {
        if ($unit->isSystemUnit()) {
            return $this->errorResponse(__('units.system_unit_readonly'), 403);
        }
        if ($unit->company_id !== $companyId) {
            return $this->errorResponse('Forbidden', 403);
        }

        return null;
    }

    private function invalidateUnitsAndProductSearchCaches(): void
    {
        CacheService::invalidateUnitsCache();
        CacheService::invalidateProductsCache();
    }
}
