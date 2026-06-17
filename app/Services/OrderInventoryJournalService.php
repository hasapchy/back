<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Sale;

class OrderInventoryJournalService
{
    /**
     * @param  Order  $order
     * @param  array<int, array{quantity: float, product_name: string}>  $warehouseStocksToUpdate
     * @param  int  $warehouseId
     * @return void
     */
    public function syncInventoryForOrder(Order $order, array $warehouseStocksToUpdate, int $warehouseId): void
    {
    }

    /**
     * @param  Order  $order
     * @param  array<int, array{quantity: float, product_name: string}>  $warehouseStocksToUpdate
     * @param  int  $warehouseId
     * @return void
     */
    public function issueInventoryForOrder(Order $order, array $warehouseStocksToUpdate, int $warehouseId): void
    {
    }

    /**
     * @param  Sale  $sale
     * @param  array<int, array{product_id: int, quantity: float}>  $products
     * @param  int  $warehouseId
     * @return void
     */
    public function issueInventoryForSale(Sale $sale, array $products, int $warehouseId): void
    {
    }
}
