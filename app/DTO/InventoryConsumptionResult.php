<?php

namespace App\DTO;

final class InventoryConsumptionResult
{
    /**
     * @param  list<array{layer_id: int, quantity: float, unit_cost: float, total_cost: float}>  $lines
     */
    public function __construct(
        public float $totalCost,
        public array $lines = [],
    ) {}
}
