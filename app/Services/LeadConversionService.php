<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\OrdersRepository;
use App\Support\SimpleUser;
use App\Services\Timeline\TimelineCache;
use Illuminate\Validation\ValidationException;

class LeadConversionService
{
    public function __construct(
        protected OrdersRepository $ordersRepository
    ) {
    }

    /**
     * @return void
     */
    public function syncOrderForSuccessStatus(Lead $lead, User $user): void
    {
        $lead->loadMissing('status');
        if (! $lead->status || $lead->status->kanban_outcome !== 'success') {
            return;
        }
        if ($lead->order_id) {
            return;
        }
        if (! $user->can('create', Order::class)) {
            throw ValidationException::withMessages([
                'status_id' => ['Нет прав на создание заказа.'],
            ]);
        }

        $warehouseId = $this->resolveFirstAccessibleWarehouseId($user, (int) $lead->company_id);
        if ($warehouseId === null) {
            throw ValidationException::withMessages([
                'status_id' => ['Нет доступного склада для создания заказа.'],
            ]);
        }

        $cashId = $this->resolveFirstAccessibleCashRegisterId($user, (int) $lead->company_id);
        if ($cashId === null && ! SimpleUser::matches($user)) {
            throw ValidationException::withMessages([
                'status_id' => ['Нет доступной кассы для создания заказа.'],
            ]);
        }

        $categoryId = $this->resolveCategoryId($user, (int) $lead->company_id);
        if ($categoryId === null) {
            throw ValidationException::withMessages([
                'status_id' => ['Не найдена категория заказа для компании.'],
            ]);
        }

        $lead->refresh();
        if ($lead->order_id) {
            return;
        }
        $lead->loadMissing('status');
        if (! $lead->status || $lead->status->kanban_outcome !== 'success') {
            return;
        }

        $order = $this->ordersRepository->createItem([
            'creator_id' => (int) $user->id,
            'client_id' => (int) $lead->client_id,
            'warehouse_id' => $warehouseId,
            'cash_id' => $cashId,
            'project_id' => null,
            'status_id' => 1,
            'category_id' => $categoryId,
            'discount' => 0,
            'discount_type' => 'percent',
            'description' => '',
            'date' => now(),
            'note' => (string) ($lead->comment ?? ''),
            'products' => [],
            'temp_products' => [],
            'client_balance_id' => null,
        ]);

        $lead->update(['order_id' => $order->id]);
        TimelineCache::forget('lead', (int) $lead->id, (int) $lead->company_id);
    }

    /**
     * @return int|null
     */
    protected function resolveFirstAccessibleWarehouseId(User $user, int $companyId): ?int
    {
        $warehouses = Warehouse::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        foreach ($warehouses as $warehouse) {
            if ($user->can('view', $warehouse)) {
                return (int) $warehouse->id;
            }
        }

        return null;
    }

    /**
     * @return int|null
     */
    protected function resolveFirstAccessibleCashRegisterId(User $user, int $companyId): ?int
    {
        $items = CashRegister::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        foreach ($items as $cash) {
            if ($user->can('view', $cash)) {
                return (int) $cash->id;
            }
        }

        return null;
    }

    /**
     * @return int|null
     */
    protected function resolveCategoryId(User $user, int $companyId): ?int
    {
        if (SimpleUser::matches($user)) {
            return SimpleUser::rootCategoryIdForCurrentCompany($user);
        }

        return Category::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->value('id');
    }
}
