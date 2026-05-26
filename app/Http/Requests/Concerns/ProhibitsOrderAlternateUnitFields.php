<?php

namespace App\Http\Requests\Concerns;

trait ProhibitsOrderAlternateUnitFields
{
    /**
     * Заказы не поддерживают альтернативные единицы (orig_unit / orig_quantity), как складские документы.
     *
     * @return array<string, string>
     */
    protected function orderAlternateUnitProhibitedRules(): array
    {
        return [
            'products.*.orig_unit_id' => 'prohibited',
            'products.*.orig_quantity' => 'prohibited',
            'temp_products.*.orig_unit_id' => 'prohibited',
            'temp_products.*.orig_quantity' => 'prohibited',
        ];
    }
}
