<?php

namespace App\Repositories;

use App\Models\LeadSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LeadSourceRepository extends BaseRepository
{
    /**
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination(int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $companyId = $this->getCurrentCompanyId();

        return LeadSource::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when(! $companyId, fn ($q) => $q->whereNull('company_id'))
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return Collection<int, LeadSource>
     */
    public function getAllItems(): Collection
    {
        $companyId = $this->getCurrentCompanyId();

        return LeadSource::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when(! $companyId, fn ($q) => $q->whereNull('company_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(array $data): LeadSource
    {
        return LeadSource::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(int $id, array $data): LeadSource
    {
        $item = LeadSource::query()->findOrFail($id);
        $item->update($data);

        return $item->fresh();
    }

    /**
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        $item = LeadSource::query()->findOrFail($id);
        if ($item->leads()->whereNotNull('lead_source_id')->exists()) {
            throw ValidationException::withMessages([
                'id' => ['Нельзя удалить источник, который указан у лидов.'],
            ]);
        }
        $item->delete();

        return true;
    }
}
