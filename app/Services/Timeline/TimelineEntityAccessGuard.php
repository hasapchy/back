<?php

namespace App\Services\Timeline;

use App\Models\Client;
use App\Models\Lead;
use App\Models\News;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Sale;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhMovement;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use App\Repositories\CommentsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TimelineEntityAccessGuard
{
    /**
     * @var array<string, list<string>>
     */
    private const PERMISSIONS_BY_API_TYPE = [
        'order' => ['orders_view_all', 'orders_view', 'orders_simple_view_all', 'orders_simple_view'],
        'sale' => ['sales_view_all', 'sales_view'],
        'transaction' => ['transactions_view_all', 'transactions_view'],
        'client' => ['clients_view_all', 'clients_view', 'settings_client_balance_view_own'],
        'product' => ['products_view_all', 'products_view'],
        'project' => ['projects_view_all', 'projects_view'],
        'task' => ['tasks_view_all', 'tasks_view'],
        'project_contract' => ['projects_view_all', 'projects_view'],
        'lead' => ['leads_view_all', 'leads_view_own'],
        'wh_receipt' => ['warehouse_receipts_view_all', 'warehouse_receipts_view_own'],
        'wh_writeoff' => ['warehouse_writeoffs_view_all', 'warehouse_writeoffs_view_own'],
        'wh_movement' => ['warehouse_movements_view_all', 'warehouse_movements_view_own'],
        'wh_purchase' => ['warehouse_purchases_view_all', 'warehouse_purchases_view_own'],
        'news' => [],
    ];

    public function __construct(
        private CommentsRepository $commentsRepository
    ) {}

    /**
     * @param User $user
     * @param string $apiType
     * @param int $entityId
     * @param int $companyId
     * @return Model
     */
    public function resolveEntityForCompany(User $user, string $apiType, int $entityId, int $companyId): Model
    {
        if (! $this->canView($user, $apiType, $entityId, $companyId)) {
            abort(404);
        }

        $modelClass = $this->commentsRepository->resolveType($apiType);

        return $this->scopedQuery($modelClass, $companyId)->whereKey($entityId)->firstOrFail();
    }

    /**
     * @param User $user
     * @param string $apiType
     * @param int $entityId
     * @param int|null $companyId
     * @return bool
     */
    public function canView(User $user, string $apiType, int $entityId, ?int $companyId = null): bool
    {
        if ($companyId === null || $companyId < 1) {
            return false;
        }

        if (! $this->userBelongsToCompany($user, $companyId)) {
            return false;
        }

        if (! $this->userHasTimelinePermission($user, $apiType, $companyId)) {
            return false;
        }

        try {
            $modelClass = $this->commentsRepository->resolveType($apiType);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $this->scopedQuery($modelClass, $companyId)->whereKey($entityId)->exists();
    }

    /**
     * @param User $user
     * @param string $apiType
     * @param list<int> $entityIds
     * @param int $companyId
     * @return list<int>
     */
    public function filterAccessibleEntityIds(User $user, string $apiType, array $entityIds, int $companyId): array
    {
        if ($companyId < 1 || ! $this->userBelongsToCompany($user, $companyId)) {
            return [];
        }

        if (! $this->userHasTimelinePermission($user, $apiType, $companyId)) {
            return [];
        }

        try {
            $modelClass = $this->commentsRepository->resolveType($apiType);
        } catch (InvalidArgumentException) {
            return [];
        }

        $entityIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $entityIds),
            static fn (int $id) => $id > 0
        )));

        if ($entityIds === []) {
            return [];
        }

        return $this->scopedQuery($modelClass, $companyId)
            ->whereIn('id', $entityIds)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param User $user
     * @param int $companyId
     * @return bool
     */
    private function userBelongsToCompany(User $user, int $companyId): bool
    {
        return $user->companies()->where('companies.id', $companyId)->exists();
    }

    /**
     * @param User $user
     * @param string $apiType
     * @param int $companyId
     * @return bool
     */
    private function userHasTimelinePermission(User $user, string $apiType, int $companyId): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! array_key_exists($apiType, self::PERMISSIONS_BY_API_TYPE)) {
            return false;
        }

        $required = self::PERMISSIONS_BY_API_TYPE[$apiType];
        if ($required === []) {
            return true;
        }

        $names = $user->getAllPermissionsForCompany($companyId)->pluck('name');

        foreach ($required as $permission) {
            if ($names->contains($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param int $companyId
     * @return Builder<Model>
     */
    private function scopedQuery(string $modelClass, int $companyId): Builder
    {
        $query = $modelClass::query();

        return match ($modelClass) {
            Order::class => $query->whereHas(
                'cashRegister',
                fn (Builder $cr) => $cr->where('company_id', $companyId)
            ),
            Sale::class => $query->where(function (Builder $q) use ($companyId) {
                $q->whereHas('cashRegister', fn (Builder $cr) => $cr->where('company_id', $companyId))
                    ->orWhereHas('warehouse', fn (Builder $w) => $w->where('company_id', $companyId));
            }),
            Transaction::class => $query->whereHas(
                'cashRegister',
                fn (Builder $cr) => $cr->where('company_id', $companyId)
            ),
            Client::class => $query->forCompany($companyId),
            Product::class => $query->forCompany($companyId),
            Project::class => $query->where('company_id', $companyId),
            Task::class => $query->where('company_id', $companyId),
            Lead::class => $query->where('company_id', $companyId),
            ProjectContract::class => $query->whereHas('project', fn (Builder $p) => $p->where('company_id', $companyId)),
            WhReceipt::class => $query->whereHas('warehouse', fn (Builder $w) => $w->where('company_id', $companyId)),
            WhWriteoff::class => $query->whereHas('warehouse', fn (Builder $w) => $w->where('company_id', $companyId)),
            WhMovement::class => $query
                ->whereHas('warehouseFrom', fn (Builder $w) => $w->where('company_id', $companyId))
                ->whereHas('warehouseTo', fn (Builder $w) => $w->where('company_id', $companyId)),
            WhPurchase::class => $query->whereHas('supplier', fn (Builder $c) => $c->where('company_id', $companyId)),
            News::class => $query->where('company_id', $companyId),
            default => throw new InvalidArgumentException("Timeline scope is not configured for {$modelClass}"),
        };
    }
}
