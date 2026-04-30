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
            ->where('id', $id);

        $this->addCompanyFilterThroughRelation($query, 'warehouse');

        return $query->first();
    }

    /**
     * @return LengthAwarePaginator<Inventory>
     */
    public function getItemsPaginated(int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $query = Inventory::query()
            ->with(['warehouse:id,name', 'creator:id,name', 'finalizedBy:id,name'])
            ->orderByDesc('id');

        $this->addCompanyFilterThroughRelation($query, 'warehouse');

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
