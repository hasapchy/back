<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Transaction;

final class OrderPaymentLimitService
{
    private const EPSILON = 0.01;

    /**
     * @return int|null
     */
    public function resolveOrderId(?int $orderId, ?string $sourceType, ?int $sourceId): ?int
    {
        if ($orderId !== null && $orderId > 0) {
            return $orderId;
        }

        if ($sourceType && $sourceId && str_contains($sourceType, 'Order')) {
            return $sourceId;
        }

        return null;
    }

    /**
     * @return float
     */
    public function remainingDefault(Order $order, ?int $excludeTransactionId = null): float
    {
        $total = max(0.0, (float) ($order->def_total_price ?? 0));
        $paid = max(0.0, (float) ($order->paid_amount ?? 0));

        if ($excludeTransactionId !== null && $excludeTransactionId > 0) {
            $transaction = Transaction::query()->find($excludeTransactionId);
            if (
                $transaction
                && ! $transaction->is_debt
                && ! $transaction->is_deleted
            ) {
                $paid -= (float) ($transaction->def_amount ?? $transaction->orig_amount ?? 0);
                $paid = max(0.0, $paid);
            }
        }

        return max(0.0, $total - $paid);
    }

    /**
     * @return float
     */
    public function paymentAmountInDefault(
        float $origAmount,
        int $currencyId,
        ?int $companyId,
        ?string $date = null,
    ): float {
        $defaultCurrency = Currency::query()
            ->where('is_default', true)
            ->where(function ($query) use ($companyId) {
                if ($companyId !== null) {
                    $query->where('company_id', $companyId)->orWhereNull('company_id');
                }
            })
            ->first();

        $fromCurrency = Currency::query()->find($currencyId);
        if (! $defaultCurrency || ! $fromCurrency) {
            return $origAmount;
        }

        if ((int) $fromCurrency->id === (int) $defaultCurrency->id) {
            return $origAmount;
        }

        return CurrencyConverter::convert(
            $origAmount,
            $fromCurrency,
            $defaultCurrency,
            null,
            $companyId,
            $date ?? now(),
        );
    }

    /**
     * @return bool
     */
    public function exceedsRemaining(
        Order $order,
        float $origAmount,
        int $currencyId,
        ?int $companyId,
        ?string $date = null,
        ?int $excludeTransactionId = null,
    ): bool {
        $remaining = $this->remainingDefault($order, $excludeTransactionId);
        $paymentDefault = $this->paymentAmountInDefault($origAmount, $currencyId, $companyId, $date);

        return $paymentDefault > $remaining + self::EPSILON;
    }
}
