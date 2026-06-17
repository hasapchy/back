<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

class UnitRepository extends BaseRepository
{
    /**
     * Company-scoped catalog of units.
     *
     * @param  int|null  $companyId
     * @return Collection<int, Unit>
     */
    public function getCompanyCatalog(?int $companyId): Collection
    {
        return Unit::forCompanyCatalog($companyId)->get();
    }

    /**
     * Persist a new unit.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Unit
    {
        $unit = new Unit;
        $unit->company_id = $data['company_id'];
        $unit->name = $data['name'];
        $unit->short_name = $data['short_name'];
        $unit->save();

        return $unit;
    }

    /**
     * Find a unit by id or fail.
     */
    public function findOrFail(int $id): Unit
    {
        return Unit::query()->whereKey($id)->firstOrFail();
    }

    /**
     * Update an existing unit.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Unit $unit, array $data): Unit
    {
        $unit->name = $data['name'];
        $unit->short_name = $data['short_name'];
        $unit->save();

        return $unit;
    }

    /**
     * Delete a unit.
     */
    public function delete(Unit $unit): void
    {
        $unit->delete();
    }

    /**
     * Whether any product references the unit.
     */
    public function isUsedByProducts(int $unitId): bool
    {
        return Product::query()->where('unit_id', $unitId)->exists();
    }

    /**
     * Whether any unit conversion references the unit.
     */
    public function isUsedByConversions(int $unitId): bool
    {
        return ProductUnitConversion::query()
            ->where(function ($query) use ($unitId) {
                $query->where('parent_unit_id', $unitId)->orWhere('child_unit_id', $unitId);
            })
            ->exists();
    }
}
