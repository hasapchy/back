<?php

namespace App\Services;

use App\Models\Currency;

class CurrencyConverter
{
    /**
     * Convert amount from source currency to target currency.
     *
     * Conversion logic:
     * 1. If currencies are the same - return amount unchanged
     * 2. If one currency is manat (base) - convert directly
     * 3. If both currencies are not manat - convert through manat
     *
     * @param float $amount
     * @param Currency $fromCurrency Source currency
     * @param Currency $toCurrency Target currency
     * @param Currency|null $defaultCurrency Base currency (manat), if not provided, will be selected from DB
     * @param int|null $companyId Company ID
     * @param string|null $date Date for exchange rate
     * @return float
     */
    public static function convert(float $amount, Currency $fromCurrency, Currency $toCurrency, ?Currency $defaultCurrency = null, ?int $companyId = null, ?string $date = null): float
    {
        if (!$defaultCurrency) {
            $defaultCurrency = Currency::where('is_default', true)->first();
        }

        if ($fromCurrency->id === $toCurrency->id) {
            return $amount;
        }

        $fromRate = $fromCurrency->getExchangeRateForCompany($companyId, $date);
        $toRate = $toCurrency->getExchangeRateForCompany($companyId, $date);

        if ($fromCurrency->id === $defaultCurrency->id) {
            return $amount / $toRate;
        }

        if ($toCurrency->id === $defaultCurrency->id) {
            return $amount * $fromRate;
        }

        $amountInManat = $amount * $fromRate;
        return $amountInManat / $toRate;
    }
}
