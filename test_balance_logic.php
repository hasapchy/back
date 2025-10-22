<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Получаем первого клиента
$client = \App\Models\Client::first();

if (!$client) {
    echo "No clients found\n";
    exit;
}

echo "Client ID: {$client->id}\n";
echo "Client Name: {$client->first_name} {$client->last_name}\n\n";

// Проверяем баланс из client_balances
$clientBalance = \App\Models\ClientBalance::where('client_id', $client->id)->first();
echo "Balance from client_balances table: " . ($clientBalance ? $clientBalance->balance : '0') . "\n\n";

// Считаем баланс из транзакций (как в модели Transaction)
$transactions = \App\Models\Transaction::where('client_id', $client->id)->get();
$calculatedBalance = 0;

echo "Transactions:\n";
foreach ($transactions as $t) {
    $sign = $t->type == 1 ? '+' : '-';
    $calculatedBalance += ($t->type == 1 ? $t->amount : -$t->amount);
    echo "  ID:{$t->id} Type:{$t->type} Amount:{$t->amount} Source:{$t->source_type} → {$sign}{$t->amount}\n";
}

echo "\nCalculated Balance (from transactions): {$calculatedBalance}\n";

// Считаем баланс через SQL (как в ClientsRepository после исправления)
$sqlBalance = \Illuminate\Support\Facades\DB::table('clients')
    ->where('id', $client->id)
    ->selectRaw('(
        SELECT COALESCE(
            SUM(
                CASE
                    WHEN t.type = 1 THEN t.amount
                    ELSE -t.amount
                END
            ), 0
        )
        FROM transactions t
        WHERE t.client_id = clients.id
    ) as balance_amount')
    ->first();

echo "SQL Balance (from repository logic): " . ($sqlBalance ? $sqlBalance->balance_amount : '0') . "\n\n";

// Проверяем заказы
$orders = \App\Models\Order::where('client_id', $client->id)->get();
$ordersTotal = 0;

echo "Orders:\n";
foreach ($orders as $order) {
    $orderTotal = $order->price - $order->discount;
    $ordersTotal += $orderTotal;
    echo "  Order ID:{$order->id} Price:{$order->price} Discount:{$order->discount} Total:{$orderTotal}\n";
}

if ($orders->isEmpty()) {
    echo "  No orders found\n";
}

echo "\nOrders Total: {$ordersTotal}\n";
echo "Final Balance (Transactions + Orders): " . ($calculatedBalance + $ordersTotal) . "\n";

// Полный SQL как в репозитории (с заказами)
$fullSqlBalance = \Illuminate\Support\Facades\DB::table('clients')
    ->where('id', $client->id)
    ->selectRaw('(
        SELECT COALESCE(
            SUM(
                CASE
                    WHEN t.type = 1 THEN t.amount
                    ELSE -t.amount
                END
            ), 0
        )
        FROM transactions t
        WHERE t.client_id = clients.id
    ) + (
        SELECT COALESCE(SUM(o.price - o.discount), 0)
        FROM orders o
        WHERE o.client_id = clients.id
    ) as balance_amount')
    ->first();

echo "Full SQL Balance (Transactions + Orders): " . ($fullSqlBalance ? $fullSqlBalance->balance_amount : '0') . "\n";

