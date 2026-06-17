<?php

namespace App\Support;

class JournalTemplateKeys
{
    public const RECEIPT_INVENTORY = 'receipt_inventory';
    public const RECEIPT_COST_ADJUSTMENT = 'receipt_cost_adjustment';
    public const ORDER_REVENUE = 'order_revenue';
    public const ORDER_COGS = 'order_cogs';
    public const SALE_REVENUE = 'sale_revenue';
    public const SALE_COGS = 'sale_cogs';
    public const SALARY_ACCRUAL = 'salary_accrual';
    public const SALARY_PAYMENT = 'salary_payment';
    public const LEGACY_TRANSACTION = 'legacy_transaction';
    public const PERIOD_CLOSE = 'period_close';
    public const MANUAL = 'manual';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::RECEIPT_INVENTORY,
            self::RECEIPT_COST_ADJUSTMENT,
            self::ORDER_REVENUE,
            self::ORDER_COGS,
            self::SALE_REVENUE,
            self::SALE_COGS,
            self::SALARY_ACCRUAL,
            self::SALARY_PAYMENT,
            self::LEGACY_TRANSACTION,
            self::PERIOD_CLOSE,
            self::MANUAL,
        ];
    }
}
