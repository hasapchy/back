<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionCategory;

class TransactionCategorySeeder extends Seeder
{
    public function run()
    {
        TransactionCategory::updateOrCreate(['id' => 1], ['name' => 'SALE', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 2], ['name' => 'CUSTOMER_PAYMENT', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 3], ['name' => 'PREPAYMENT', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 4], ['name' => 'OTHER_INCOME', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 5], ['name' => 'CUSTOMER_REFUND', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 6], ['name' => 'GOODS_PAYMENT', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 7], ['name' => 'SALARY_PAYMENT', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 8], ['name' => 'TAX_PAYMENT', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 9], ['name' => 'RENT_PAYMENT', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 10], ['name' => 'FUEL_TRANSPORT', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 11], ['name' => 'UTILITIES', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 12], ['name' => 'ADVERTISING', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 13], ['name' => 'PHONE_INTERNET', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 14], ['name' => 'OTHER_EXPENSE', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 15], ['name' => 'FOOD', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 16], ['name' => 'LOGISTICS', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 17], ['name' => 'TRANSFER_OUTCOME', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 18], ['name' => 'TRANSFER_INCOME', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 19], ['name' => 'NON_CASH', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 20], ['name' => 'BONUS', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 21], ['name' => 'BALANCE_ADJUSTMENT_EXP', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 22], ['name' => 'BALANCE_ADJUSTMENT_INC', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 23], ['name' => 'ADVANCE', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 24], ['name' => 'SALARY_ACCRUAL', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 25], ['name' => 'ORDER', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 26], ['name' => 'PREMIUM', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 27], ['name' => 'FINE', 'type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 28], ['name' => 'RENT_INCOME', 'type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 29], ['name' => 'CUSTOMER_PAYMENT', 'type' => 1, 'user_id' => 1]);
    }
}
