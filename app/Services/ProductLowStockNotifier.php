<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WarehouseStock;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use App\Services\InAppNotifications\UserNotificationSettingsService;
use Illuminate\Support\Facades\DB;

class ProductLowStockNotifier
{
    public function __construct(
        private readonly InAppNotificationDispatcher $notificationDispatcher,
        private readonly UserNotificationSettingsService $notificationSettingsService,
    ) {
    }

    /**
     * @param  WarehouseStock  $stock
     * @return bool
     */
    public function handleStockChanged(WarehouseStock $stock): bool
    {
        $companyId = (int) WarehouseStock::query()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')
            ->where('warehouse_stocks.id', (int) $stock->id)
            ->value('warehouses.company_id');

        if ($companyId < 1) {
            return false;
        }

        $product = Product::query()->find((int) $stock->product_id);
        if (! $product) {
            return false;
        }

        $minQuantity = $product->stock_min_quantity !== null ? (float) $product->stock_min_quantity : null;
        if (! $product->isProductType() || ! (bool) $product->stock_alert_notify || $minQuantity === null || $minQuantity <= 0) {
            return $this->disarmIfNeeded($product);
        }

        $totalQuantity = $this->sumCompanyProductStock($companyId, (int) $product->id);
        $isBelow = Product::isBelowMinStockByValues($totalQuantity, true, $minQuantity);
        $isArmed = (bool) $product->low_stock_notification_armed;

        if ($isBelow && ! $isArmed) {
            $recipientIds = $this->resolveRecipientIds($companyId, (int) $product->id);
            if ($recipientIds === []) {
                $recipientIds = [1];
            }

            $eligibleRecipientIds = $this->notificationSettingsService->filterEligibleRecipients(
                $companyId,
                'stock_low',
                $recipientIds,
                null
            );

            if ($eligibleRecipientIds === []) {
                return false;
            }

            $this->notificationDispatcher->dispatchToUserIds(
                $companyId,
                'stock_low',
                $eligibleRecipientIds,
                null,
                'Низкий остаток товара',
                $product->name.' (SKU: '.$product->sku.') ниже минимального порога',
                [
                    'route' => '/products/'.$product->id,
                    'product_id' => (int) $product->id,
                    'stock_quantity' => $totalQuantity,
                    'stock_min_quantity' => $minQuantity,
                ]
            );

            $product->low_stock_notification_armed = true;
            $product->save();

            return true;
        }

        if (! $isBelow && $isArmed) {
            $product->low_stock_notification_armed = false;
            $product->save();

            return true;
        }

        return false;
    }

    /**
     * @param  int  $companyId
     * @param  int  $productId
     * @return float
     */
    private function sumCompanyProductStock(int $companyId, int $productId): float
    {
        return (float) WarehouseStock::query()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')
            ->where('warehouses.company_id', $companyId)
            ->where('warehouse_stocks.product_id', $productId)
            ->sum('warehouse_stocks.quantity');
    }

    /**
     * @param  int  $companyId
     * @param  int  $productId
     * @return array<int>
     */
    private function resolveRecipientIds(int $companyId, int $productId): array
    {
        $primaryCategoryId = DB::table('product_categories')
            ->where('product_id', $productId)
            ->min('category_id');

        if (! $primaryCategoryId) {
            return [];
        }

        return DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->leftJoin('category_users', function ($join) use ($primaryCategoryId) {
                $join->on('category_users.user_id', '=', 'users.id')
                    ->where('category_users.category_id', '=', (int) $primaryCategoryId);
            })
            ->where('company_user.company_id', $companyId)
            ->where('users.is_active', true)
            ->whereNotNull('category_users.id')
            ->pluck('users.id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Product  $product
     * @return bool
     */
    private function disarmIfNeeded(Product $product): bool
    {
        if (! (bool) $product->low_stock_notification_armed) {
            return false;
        }

        $product->low_stock_notification_armed = false;
        $product->save();

        return true;
    }
}
