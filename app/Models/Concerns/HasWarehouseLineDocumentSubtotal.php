<?php

namespace App\Models\Concerns;

trait HasWarehouseLineDocumentSubtotal
{
    /**
     * Сумма строки в валюте документа (касса/закупка): orig_unit_price × qty, иначе price × qty.
     */
    public function documentCurrencySubtotal(): float
    {
        $quantity = (float) $this->quantity;
        $origUnitPrice = $this->orig_unit_price !== null ? (float) $this->orig_unit_price : 0.0;
        if ($origUnitPrice > 0) {
            return $origUnitPrice * $quantity;
        }

        return (float) $this->price * $quantity;
    }

    /**
     * Цена за единицу в валюте документа.
     */
    public function documentCurrencyUnitPrice(): float
    {
        $origUnitPrice = $this->orig_unit_price !== null ? (float) $this->orig_unit_price : 0.0;
        if ($origUnitPrice > 0) {
            return $origUnitPrice;
        }

        return (float) $this->price;
    }
}
