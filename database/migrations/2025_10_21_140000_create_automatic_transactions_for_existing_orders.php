<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Currency;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Создаем автоматические долговые транзакции для существующих заказов
        // которые еще не имеют автоматической транзакции

        $defaultCurrency = Currency::where('is_default', true)->first();

        if (!$defaultCurrency) {
            throw new Exception('Валюта по умолчанию не найдена');
        }

        // Получаем все заказы, которые НЕ имеют автоматической долговой транзакции
        $orders = Order::whereDoesntHave('transactions', function($query) {
            $query->where('type', 1)
                  ->where('is_debt', true);
        })->get();

        echo "Найдено заказов без автоматической транзакции: " . $orders->count() . "\n";

        $created = 0;

        foreach ($orders as $order) {
            // Рассчитываем сумму заказа
            $totalPrice = $order->price - $order->discount;

            // Проверяем, есть ли уже оплаты по заказу
            $paidAmount = Transaction::where('source_type', 'App\Models\Order')
                ->where('source_id', $order->id)
                ->sum('amount');

            // Создаем долговую транзакцию только на неоплаченную часть
            $debtAmount = $totalPrice - $paidAmount;

            if ($debtAmount > 0) {
                Transaction::create([
                    'client_id'    => $order->client_id,
                    'amount'       => $debtAmount,
                    'orig_amount'  => $debtAmount,
                    'type'         => 1, // доход (клиент должен)
                    'is_debt'      => true, // долг
                    'cash_id'      => $order->cash_id,
                    'category_id'  => 1,
                    'source_type'  => 'App\Models\Order',
                    'source_id'    => $order->id,
                    'date'         => $order->date,
                    'note'         => 'Автоматическая транзакция заказа (миграция)',
                    'user_id'      => $order->user_id,
                    'project_id'   => $order->project_id,
                    'currency_id'  => $defaultCurrency->id,
                ]);

                $created++;

                if ($created % 100 == 0) {
                    echo "Создано транзакций: {$created}\n";
                }
            }
        }

        echo "Всего создано автоматических транзакций для заказов: {$created}\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем автоматические транзакции заказов созданные этой миграцией
        Transaction::where('source_type', 'App\Models\Order')
            ->where('type', 1)
            ->where('is_debt', true)
            ->where('note', 'like', '%миграция%')
            ->delete();
    }
};


