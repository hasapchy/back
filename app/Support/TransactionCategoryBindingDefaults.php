<?php

namespace App\Support;

class TransactionCategoryBindingDefaults
{
    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        return [
            TransactionCategoryBindingKeys::ORDER => 1,
            TransactionCategoryBindingKeys::CONTRACT => 30,
            TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE => 6,
            TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT => 6,
            TransactionCategoryBindingKeys::ADJUSTMENT_INCOME => 22,
            TransactionCategoryBindingKeys::ADJUSTMENT_OUTCOME => 21,
            TransactionCategoryBindingKeys::TRANSACTION_DEFAULT_INCOME => 4,
            TransactionCategoryBindingKeys::TRANSACTION_DEFAULT_OUTCOME => 14,
            TransactionCategoryBindingKeys::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE => 16,
            TransactionCategoryBindingKeys::PRESET_EMPLOYEE_BONUS => 26,
            TransactionCategoryBindingKeys::PRESET_EMPLOYEE_PENALTY => 27,
            TransactionCategoryBindingKeys::PRESET_EMPLOYEE_SALARY_ACCRUAL => 24,
            TransactionCategoryBindingKeys::PRESET_EMPLOYEE_SALARY_PAYMENT => 7,
            TransactionCategoryBindingKeys::PRESET_EMPLOYEE_ADVANCE => 23,
            TransactionCategoryBindingKeys::CASH_TRANSFER_OUTCOME => 17,
            TransactionCategoryBindingKeys::CASH_TRANSFER_INCOME => 18,
            TransactionCategoryBindingKeys::WAREHOUSE_WRITEOFF_SUPPLIER_RETURN => 4,
        ];
    }
}
