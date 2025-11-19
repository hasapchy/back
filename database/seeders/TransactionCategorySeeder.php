<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionCategory;

class TransactionCategorySeeder extends Seeder
{
    public function run()
    {
        TransactionCategory::updateOrCreate(['id' => 1, 'name' => 'Продажа'], ['type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 2, 'name' => 'Оплата покупателя за услугу, товар'], ['type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 3, 'name' => 'Предоплата'], ['type' => 1, 'user_id' => 1]);
        // TransactionCategory::updateOrCreate(['name' => 'Возврат денег от поставщика'], ['type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 4, 'name' => 'Прочий приход денег'], ['type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 5, 'name' => 'Возврат денег покупателю'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 6, 'name' => 'Оплата поставщикам товаров, запчастей'], ['type' => 0, 'user_id' => 1]);
        // TransactionCategory::updateOrCreate(['name' => 'Выплата'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 7, 'name' => 'Выплата зарплаты'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 8, 'name' => 'Выплата налогов'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 9, 'name' => 'Оплата аренды'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 10, 'name' => 'Оплата ГСМ, транспортных услуг'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 11, 'name' => 'Оплата коммунальных расходов'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 12, 'name' => 'Оплата рекламы'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 13, 'name' => 'Оплата телефона и интернета'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 14, 'name' => 'Прочий расход денег'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 15, 'name' => 'Питание'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 16, 'name' => 'Логистика'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 17, 'name' => 'Перемещение'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 19, 'name' => 'Безналичный'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 20, 'name' => 'Бонус'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 21, 'name' => 'Корректировка остатка'], ['type' => 0, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 22, 'name' => 'Корректировка остатка'], ['type' => 1, 'user_id' => 1]);
        TransactionCategory::updateOrCreate(['id' => 25, 'name' => 'Заказ'], ['type' => 1, 'user_id' => 1]);
    }
}
