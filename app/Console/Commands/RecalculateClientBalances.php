<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Transaction;
use App\Models\Sale;
use App\Models\Order;
use App\Models\WhReceipt;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\DB;

class RecalculateClientBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clients:recalculate-balances {--client-id= : Recalculate balance for specific client ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate client balances based on all transactions, sales, orders, and receipts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting client balances recalculation...');

        $clientId = $this->option('client-id');

        if ($clientId) {
            $this->recalculateClientBalance($clientId);
        } else {
            $this->recalculateAllClientBalances();
        }

        $this->info('Client balances recalculation completed!');
    }

    private function recalculateAllClientBalances()
    {
        $clients = Client::all();
        $progressBar = $this->output->createProgressBar($clients->count());
        $progressBar->start();

        foreach ($clients as $client) {
            $this->recalculateClientBalance($client->id);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
    }

    private function recalculateClientBalance($clientId)
    {
        $client = Client::find($clientId);
        if (!$client) {
            $this->error("Client with ID {$clientId} not found.");
            return;
        }

        $this->line("Recalculating balance for client: {$client->first_name} {$client->last_name} (ID: {$clientId})");

        // Получаем валюту по умолчанию
        $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
        if (!$defaultCurrency) {
            $this->error('Default currency not found. Please set a default currency first.');
            return;
        }

        $totalBalance = 0;

        // 1. Транзакции
        $transactions = Transaction::where('client_id', $clientId)->get();
        foreach ($transactions as $transaction) {
            $convertedAmount = $this->convertToDefaultCurrency($transaction->amount, $transaction->currency_id, $defaultCurrency->id);

            if ($transaction->type == 1) {
                // Доход: клиент нам платит - увеличиваем баланс
                $totalBalance += $convertedAmount;
            } else {
                // Расход: мы клиенту платим - уменьшаем баланс
                $totalBalance -= $convertedAmount;
            }
        }

        // 2. Продажи (если не через кассу - это долг клиента)
        $sales = Sale::where('client_id', $clientId)->whereNull('cash_id')->get();
        foreach ($sales as $sale) {
            $convertedAmount = $this->convertToDefaultCurrency($sale->total_price, $sale->currency_id, $defaultCurrency->id);
            // Продажа в долг - увеличиваем долг клиента (уменьшаем баланс)
            $totalBalance -= $convertedAmount;
        }

        // 3. Заказы (если не через кассу - это долг клиента)
        $orders = Order::where('client_id', $clientId)->whereNull('cash_id')->get();
        foreach ($orders as $order) {
            $convertedAmount = $this->convertToDefaultCurrency($order->total_price, $order->currency_id, $defaultCurrency->id);
            // Заказ в долг - увеличиваем долг клиента (уменьшаем баланс)
            $totalBalance -= $convertedAmount;
        }

        // 4. Оприходования (если не через кассу - это долг поставщика)
        $receipts = WhReceipt::where('supplier_id', $clientId)->whereNull('cash_id')->get();
        foreach ($receipts as $receipt) {
            $convertedAmount = $this->convertToDefaultCurrency($receipt->amount, $receipt->currency_id, $defaultCurrency->id);
            // Оприходование в долг - увеличиваем долг поставщика (уменьшаем баланс)
            $totalBalance -= $convertedAmount;
        }

        // Удаляем все существующие записи баланса для этого клиента
        ClientBalance::where('client_id', $clientId)->delete();

        // Создаем новую запись баланса
        ClientBalance::create([
            'client_id' => $clientId,
            'balance' => $totalBalance
        ]);

        $this->line("  New balance: {$totalBalance} {$defaultCurrency->symbol}");
    }

    private function convertToDefaultCurrency($amount, $fromCurrencyId, $defaultCurrencyId)
    {
        if ($fromCurrencyId == $defaultCurrencyId) {
            return $amount;
        }

        $fromCurrency = \App\Models\Currency::find($fromCurrencyId);
        $toCurrency = \App\Models\Currency::find($defaultCurrencyId);

        if (!$fromCurrency || !$toCurrency) {
            return $amount;
        }

        return CurrencyConverter::convert($amount, $fromCurrency, $toCurrency);
    }
}
