<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Transaction;
use App\Repositories\OrdersRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateOrdersPaidAmount extends Command
{
    protected $signature = 'orders:update-paid-amount';

    protected $description = 'Обновить оплаченные суммы для всех заказов на основе транзакций';

    public function handle()
    {
        $this->info('🔄 Начинаю обновление оплаченных сумм заказов...');

        $ordersRepository = new OrdersRepository();
        $totalOrders = Order::count();
        $updated = 0;
        $bar = $this->output->createProgressBar($totalOrders);
        $bar->start();

        Order::chunk(100, function ($orders) use ($ordersRepository, &$updated, $bar) {
            foreach ($orders as $order) {
                $paidAmount = Transaction::where('source_type', 'App\Models\Order')
                    ->where('source_id', $order->id)
                    ->where('is_debt', 0)
                    ->where('is_deleted', false)
                    ->sum('orig_amount');

                $order->paid_amount = (float) $paidAmount;
                $order->save();

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✅ Обновлено заказов: {$updated}");

        return Command::SUCCESS;
    }
}
