<?php

namespace App\Support;

class TransactionCategoryBindingKeys
{
    public const ORDER = 'order';
    public const CONTRACT = 'contract';
    public const WAREHOUSE_PURCHASE = 'warehouse.purchase';
    public const WAREHOUSE_RECEIPT = 'warehouse.receipt';
    public const ADJUSTMENT_INCOME = 'adjustment.income';
    public const ADJUSTMENT_OUTCOME = 'adjustment.outcome';
    public const TRANSACTION_DEFAULT_INCOME = 'transaction.default.income';
    public const TRANSACTION_DEFAULT_OUTCOME = 'transaction.default.outcome';
    public const TRANSACTION_CONTRACT_INCOME = 'transaction.contract.income';
    public const PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE = 'preset.warehouse.receipt.delivery.expense';
    public const PRESET_EMPLOYEE_BONUS = 'preset.employee.bonus';
    public const PRESET_EMPLOYEE_PENALTY = 'preset.employee.penalty';
    public const PRESET_EMPLOYEE_SALARY_ACCRUAL = 'preset.employee.salary.accrual';
    public const PRESET_EMPLOYEE_SALARY_PAYMENT = 'preset.employee.salary.payment';
    public const PRESET_EMPLOYEE_ADVANCE = 'preset.employee.advance';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ORDER,
            self::CONTRACT,
            self::WAREHOUSE_PURCHASE,
            self::WAREHOUSE_RECEIPT,
            self::ADJUSTMENT_INCOME,
            self::ADJUSTMENT_OUTCOME,
            self::TRANSACTION_DEFAULT_INCOME,
            self::TRANSACTION_DEFAULT_OUTCOME,
            self::TRANSACTION_CONTRACT_INCOME,
            self::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE,
            self::PRESET_EMPLOYEE_BONUS,
            self::PRESET_EMPLOYEE_PENALTY,
            self::PRESET_EMPLOYEE_SALARY_ACCRUAL,
            self::PRESET_EMPLOYEE_SALARY_PAYMENT,
            self::PRESET_EMPLOYEE_ADVANCE,
        ];
    }

    public static function has(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /**
     * Тип проводки (0 — расход, 1 — доход), соответствующий ключу привязки.
     */
    public static function transactionTypeForKey(string $key): ?int
    {
        return match ($key) {
            self::ORDER,
            self::CONTRACT,
            self::TRANSACTION_CONTRACT_INCOME,
            self::ADJUSTMENT_INCOME,
            self::TRANSACTION_DEFAULT_INCOME,
            self::PRESET_EMPLOYEE_PENALTY => 1,
            self::WAREHOUSE_PURCHASE,
            self::WAREHOUSE_RECEIPT,
            self::ADJUSTMENT_OUTCOME,
            self::TRANSACTION_DEFAULT_OUTCOME,
            self::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE,
            self::PRESET_EMPLOYEE_BONUS,
            self::PRESET_EMPLOYEE_SALARY_ACCRUAL,
            self::PRESET_EMPLOYEE_SALARY_PAYMENT,
            self::PRESET_EMPLOYEE_ADVANCE => 0,
            default => null,
        };
    }
}
