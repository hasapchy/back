<?php

namespace App\Repositories;

use App\Models\LeadStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LeadStatusRepository extends BaseRepository
{
    /**
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination(int $userUuid, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $companyId = $this->getCurrentCompanyId();

        return LeadStatus::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when(! $companyId, fn ($q) => $q->whereNull('company_id'))
            ->orderBy('sort')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return Collection<int, LeadStatus>
     */
    public function getAllItems(int $userUuid): Collection
    {
        $companyId = $this->getCurrentCompanyId();

        return LeadStatus::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when(! $companyId, fn ($q) => $q->whereNull('company_id'))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(array $data): LeadStatus
    {
        $this->assertKanbanOutcomeUnique(
            (int) $data['company_id'],
            $data['kanban_outcome'] ?? null,
            null
        );

        return LeadStatus::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(int $id, array $data): LeadStatus
    {
        $item = LeadStatus::query()->findOrFail($id);
        $outcome = array_key_exists('kanban_outcome', $data) ? $data['kanban_outcome'] : $item->kanban_outcome;
        $this->assertKanbanOutcomeUnique((int) $item->company_id, $outcome, $item->id);

        $item->update($data);

        return $item->fresh();
    }

    /**
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        $item = LeadStatus::query()->findOrFail($id);
        if ($item->leads()->exists()) {
            throw ValidationException::withMessages([
                'id' => ['Нельзя удалить статус с привязанными лидами.'],
            ]);
        }
        $item->delete();

        return true;
    }

    /**
     * @return void
     */
    protected function assertKanbanOutcomeUnique(int $companyId, ?string $outcome, ?int $exceptId): void
    {
        if ($outcome === null || $outcome === '') {
            return;
        }
        $q = LeadStatus::query()
            ->where('company_id', $companyId)
            ->where('kanban_outcome', $outcome);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages([
                'kanban_outcome' => ['Для компании уже задан итог канбана этого типа.'],
            ]);
        }
    }
}
