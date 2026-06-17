<?php

namespace App\Services;

use App\DTO\InventoryConsumptionResult;
use App\Enums\JournalEntryStatus;
use App\Models\InventoryLayerConsumption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Journal\OrderShipmentJournalBuilder;
use App\Services\Journal\SaleShipmentJournalBuilder;
use App\Support\CompanyContextResolver;
use App\Support\JournalTemplateKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderInventoryJournalService
{
    public function __construct(
        private readonly InventoryCostingService $costingService,
        private readonly JournalEntryService $journalEntryService,
        private readonly OrderShipmentJournalBuilder $orderBuilder,
        private readonly SaleShipmentJournalBuilder $saleBuilder,
    ) {}

    /**
     * @param  Order  $order
     * @param  array<int, array{quantity: float, product_name: string}>  $warehouseStocksToUpdate
     * @param  int  $warehouseId
     * @return void
     */
    public function syncInventoryForOrder(Order $order, array $warehouseStocksToUpdate, int $warehouseId): void
    {
        if ($warehouseStocksToUpdate === []) {
            return;
        }

        $order->loadMissing('warehouse');
        $companyId = $this->requireCompanyIdForWarehouseId($warehouseId, 'order inventory sync');
        $postedCogs = $this->journalEntryService->findBySource(
            $companyId,
            Order::class,
            (int) $order->id,
            JournalTemplateKeys::ORDER_COGS,
        );

        if ($postedCogs !== null && $postedCogs->status === JournalEntryStatus::Posted) {
            $this->assertSufficientStock($warehouseStocksToUpdate, $warehouseId);
            $this->deductStockOnly($warehouseStocksToUpdate, $warehouseId);

            return;
        }

        $this->issueInventoryForOrder($order, $warehouseStocksToUpdate, $warehouseId);
    }

    /**
     * @param  Order  $order
     * @param  array<int, array{quantity: float, product_name: string}>  $warehouseStocksToUpdate
     * @param  int  $warehouseId
     * @return void
     */
    public function issueInventoryForOrder(Order $order, array $warehouseStocksToUpdate, int $warehouseId): void
    {
        if ($warehouseStocksToUpdate === []) {
            return;
        }

        $order->loadMissing('warehouse');
        $companyId = $this->requireCompanyIdForWarehouseId($warehouseId, 'order shipment');

        $this->assertSufficientStock($warehouseStocksToUpdate, $warehouseId);

        DB::transaction(function () use ($order, $warehouseStocksToUpdate, $warehouseId, $companyId): void {
            $totalCogs = 0.0;
            $cogsLines = [];

            foreach ($warehouseStocksToUpdate as $productId => $stockData) {
                $qty = (float) $stockData['quantity'];
                $result = $this->costingService->consumeFifo(
                    $warehouseId,
                    (int) $productId,
                    $qty,
                    Order::class,
                    (int) $order->id,
                );
                $totalCogs += $result->totalCost;
                $cogsLines = array_merge($cogsLines, $result->lines);
            }

            $this->deductStockOnly($warehouseStocksToUpdate, $warehouseId);

            $cogsResult = new InventoryConsumptionResult(round($totalCogs, 5), $cogsLines);
            $cogsDrafts = $this->orderBuilder->buildCogsLines($order, $cogsResult);
            if ($cogsDrafts !== []) {
                $cogsEntry = $this->journalEntryService->createAndPost(
                    $companyId,
                    Carbon::parse($order->date),
                    'COGS for order #'.$order->id,
                    JournalTemplateKeys::ORDER_COGS,
                    $cogsDrafts,
                    Order::class,
                    (int) $order->id,
                    ['order_id' => $order->id, 'project_id' => $order->project_id],
                );

                $this->costingService->linkConsumptionsToJournalEntry(
                    Order::class,
                    (int) $order->id,
                    $cogsResult->lines,
                    (int) $cogsEntry->id,
                );
            }

            $revenueDrafts = $this->orderBuilder->buildRevenueLines($order);
            if ($revenueDrafts !== null) {
                $this->journalEntryService->createAndPost(
                    $companyId,
                    Carbon::parse($order->date),
                    'Revenue for order #'.$order->id,
                    JournalTemplateKeys::ORDER_REVENUE,
                    $revenueDrafts,
                    Order::class,
                    (int) $order->id,
                    ['order_id' => $order->id, 'project_id' => $order->project_id],
                );
            }
        });
    }

    /**
     * @param  Sale  $sale
     * @param  array<int, array{product_id: int, quantity: float}>  $products
     * @param  int  $warehouseId
     * @return void
     */
    public function issueInventoryForSale(Sale $sale, array $products, int $warehouseId): void
    {
        $goods = [];
        foreach ($products as $prod) {
            $product = Product::query()->find((int) $prod['product_id']);
            if ($product && (int) $product->type === Product::TYPE_GOODS) {
                $goods[] = $prod;
            }
        }

        if ($goods === []) {
            return;
        }

        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $companyId = CompanyContextResolver::requireWarehouseCompanyId($warehouse, 'sale shipment');

        DB::transaction(function () use ($sale, $goods, $warehouseId, $companyId): void {
            $totalCogs = 0.0;
            $cogsLines = [];

            foreach ($goods as $prod) {
                $qty = (float) $prod['quantity'];
                $result = $this->costingService->consumeFifo(
                    $warehouseId,
                    (int) $prod['product_id'],
                    $qty,
                    Sale::class,
                    (int) $sale->id,
                );
                $totalCogs += $result->totalCost;
                $cogsLines = array_merge($cogsLines, $result->lines);

                WarehouseStock::query()
                    ->where('product_id', (int) $prod['product_id'])
                    ->where('warehouse_id', $warehouseId)
                    ->decrement('quantity', $qty);
            }

            $cogsResult = new InventoryConsumptionResult(round($totalCogs, 5), $cogsLines);
            $cogsDrafts = $this->saleBuilder->buildCogsLines($sale, $cogsResult);
            if ($cogsDrafts !== []) {
                $cogsEntry = $this->journalEntryService->createAndPost(
                    $companyId,
                    Carbon::parse($sale->date),
                    'COGS for sale #'.$sale->id,
                    JournalTemplateKeys::SALE_COGS,
                    $cogsDrafts,
                    Sale::class,
                    (int) $sale->id,
                    ['sale_id' => $sale->id],
                );

                $this->costingService->linkConsumptionsToJournalEntry(
                    Sale::class,
                    (int) $sale->id,
                    $cogsResult->lines,
                    (int) $cogsEntry->id,
                );
            }

            $revenueDrafts = $this->saleBuilder->buildRevenueLines($sale);
            $this->journalEntryService->createAndPost(
                $companyId,
                Carbon::parse($sale->date),
                'Revenue for sale #'.$sale->id,
                JournalTemplateKeys::SALE_REVENUE,
                $revenueDrafts,
                Sale::class,
                (int) $sale->id,
                ['sale_id' => $sale->id],
            );
        });
    }

    /**
     * @param  int  $warehouseId
     * @param  string  $context
     * @return int
     */
    private function requireCompanyIdForWarehouseId(int $warehouseId, string $context): int
    {
        $warehouse = Warehouse::query()->find($warehouseId);

        return CompanyContextResolver::requireWarehouseCompanyId($warehouse, $context);
    }

    /**
     * @param  array<int, array{quantity: float, product_name?: string}>  $warehouseStocksToUpdate
     * @param  int  $warehouseId
     * @return void
     */
    private function assertSufficientStock(array $warehouseStocksToUpdate, int $warehouseId): void
    {
        $stockProductIds = array_keys($warehouseStocksToUpdate);
        $stocks = WarehouseStock::query()
            ->whereIn('product_id', $stockProductIds)
            ->where('warehouse_id', $warehouseId)
            ->get()
            ->keyBy('product_id');

        foreach ($warehouseStocksToUpdate as $productId => $stockData) {
            $stock = $stocks->get($productId);
            if ($stock === null) {
                $name = $stockData['product_name'] ?? (string) $productId;
                throw new \RuntimeException("Insufficient stock for '{$name}' (available: 0, required: {$stockData['quantity']})");
            }

            $available = (float) $stock->quantity;
            $required = (float) $stockData['quantity'];
            if ($available + 1e-12 < $required) {
                $name = $stockData['product_name'] ?? (string) $productId;
                throw new \RuntimeException("Insufficient stock for '{$name}' (available: {$available}, required: {$required})");
            }
        }
    }

    /**
     * @param  array<int, array{quantity: float, product_name: string}>  $warehouseStocksToUpdate
     * @param  int  $warehouseId
     * @return void
     */
    private function deductStockOnly(array $warehouseStocksToUpdate, int $warehouseId): void
    {
        foreach ($warehouseStocksToUpdate as $productId => $stockData) {
            WarehouseStock::query()
                ->where('product_id', (int) $productId)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => DB::raw('quantity - '.(float) $stockData['quantity'])]);
        }
    }
}
