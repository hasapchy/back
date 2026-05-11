<?php

namespace App\Repositories;

use App\Models\Inventory;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryRepository extends BaseRepository
{
    public function getByIdForUser(int $id): ?Inventory
    {
        $query = Inventory::query()
            ->with(['warehouse:id,name', 'creator:id,name', 'finalizedBy:id,name'])
            ->withDiscrepancyItemsCount()
            ->where('id', $id);

        $this->addCompanyFilterThroughRelation($query, 'warehouse');

        return $query->first();
    }

    /**
     * @param  array{status?: string}  $filters
     * @return LengthAwarePaginator<Inventory>
     */
    public function getItemsPaginated(int $perPage = 20, int $page = 1, array $filters = []): LengthAwarePaginator
    {
        $query = Inventory::query()
            ->with(['warehouse:id,name', 'creator:id,name', 'finalizedBy:id,name'])
            ->withDiscrepancyItemsCount()
            ->orderByDesc('id');

        $this->addCompanyFilterThroughRelation($query, 'warehouse');

        $status = $filters['status'] ?? null;
        if (in_array($status, ['in_progress', 'completed'], true)) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
