<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionCategory;

class TransactionCategorySeeder extends Seeder
{
    public function run()
    {
        TransactionCategory::create(['name' => 'Продажа', 'type' => 1,]);
        TransactionCategory::create(['name' => 'Оплата покупателя за услугу, товар', 'type' => 1]);
        TransactionCategory::create(['name' => 'Предоплата', 'type' => 1]);
        TransactionCategory::create(['name' => 'Возврат денег от поставщика', 'type' => 1]);
        TransactionCategory::create(['name' => 'Прочий приход денег', 'type' => 1]);
        TransactionCategory::create(['name' => 'Возврат денег покупателю', 'type' => 0]);
        TransactionCategory::create(['name' => 'Оплата поставщикам товаров, запчастей', 'type' => 0]);
        TransactionCategory::create(['name' => 'Выплата', 'type' => 0]);
        TransactionCategory::create(['name' => 'Выплата зарплаты', 'type' => 0]);
        TransactionCategory::create(['name' => 'Выплата налогов', 'type' => 0]);
        TransactionCategory::create(['name' => 'Оплата аренды', 'type' => 0]);
        TransactionCategory::create(['name' => 'Оплата ГСМ, транспортных услуг', 'type' => 0]);
        TransactionCategory::create(['name' => 'Оплата коммунальных расходов', 'type' => 0]);
        TransactionCategory::create(['name' => 'Оплата рекламы', 'type' => 0]);
        TransactionCategory::create(['name' => 'Оплата телефона и интернета', 'type' => 0]);
        TransactionCategory::create(['name' => 'Прочий расход денег', 'type' => 0]);
    }
}
