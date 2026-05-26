<?php

namespace App\Repositories\Concerns;

use App\Models\Currency;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;
use Illuminate\Support\Carbon;

trait ResolvesWarehouseLineOrigDisplay
{
    /**
     * @param  array<string, mixed>  $product
     * @return array{orig_unit_id: int|null, orig_quantity: float|null}
     */
    protected function resolveWarehouseLineOrigDisplay(array $product): array
    {
        $companyId = $this->getCurrentCompanyId();
        $unitRaw = $product['orig_unit_id'] ?? null;
        $unitId = $unitRaw !== null && $unitRaw !== '' ? (int) $unitRaw : null;
        $qtyRaw = array_key_exists('orig_quantity', $product) ? $product['orig_quantity'] : null;
        if ($unitId === null || $qtyRaw === null || $qtyRaw === '') {
            return ['orig_unit_id' => null, 'orig_quantity' => null];
        }
        $qty = (float) $qtyRaw;
        if ($qty <= 0) {
            return ['orig_unit_id' => null, 'orig_quantity' => null];
        }
        if ($companyId) {
            $qty = (new RoundingService())->roundQuantityForCompany((int) $companyId, $qty);
        }

        return ['orig_unit_id' => $unitId, 'orig_quantity' => $qty];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{orig_unit_price: float, orig_currency_id: int, price: float}
     */
    protected function resolveWarehouseLineOrigAmount(array $product, int $documentCurrencyId, mixed $rateDate = null): array
    {
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $rounding = new RoundingService();
        $quantity = (float) ($product['quantity'] ?? 0);
        $price = (float) ($product['price'] ?? 0);
        if ($quantity > 0 && array_key_exists('amount', $product) && $product['amount'] !== null && $product['amount'] !== '') {
            $price = (float) $product['amount'] / $quantity;
        }
        if ($companyId) {
            $price = $rounding->roundWarehouseAmountForCompany($companyId, $price);
        }

        $origUnitPrice = $price;
        $defaultCurrency = $this->getDefaultCurrency();
        if ($documentCurrencyId === (int) $defaultCurrency->id) {
            $defUnitPrice = $origUnitPrice;
        } else {
            $fromCurrency = Currency::query()->findOrFail($documentCurrencyId);
            $dateStr = Carbon::parse($rateDate ?? now())->format('Y-m-d');
            $defUnitPrice = CurrencyConverter::convert(
                $origUnitPrice,
                $fromCurrency,
                $defaultCurrency,
                null,
                $companyId,
                $dateStr
            );
            if ($companyId) {
                $defUnitPrice = $rounding->roundWarehouseAmountForCompany($companyId, $defUnitPrice);
            }
        }

        return [
            'orig_unit_price' => $origUnitPrice,
            'orig_currency_id' => $documentCurrencyId,
            'price' => $defUnitPrice,
        ];
    }
}
