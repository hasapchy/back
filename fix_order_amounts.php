<?php

/**
 * Скрипт для пересчета сумм заказов после изменения логики округления quantity
 *
 * Логика:
 * 1. Пересчитываем quantity для всех order_products с width и height используя новую логику с round(..., 2)
 * 2. Пересчитываем суммы заказов (orders.price = сумма(quantity * price))
 * 3. Пересчитываем суммы транзакций заказов (если total_price изменился)
 * 4. Пересчитываем балансы клиентов
 *
 * Возможность отката: скрипт создает backup таблицы order_products и orders
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Models\Transaction;
use App\Models\Product;
use App\Models\Client;

echo "========================================\n";
echo "Скрипт пересчета сумм заказов\n";
echo "========================================\n\n";

// Функция для расчета quantity с округлением
function calculateQuantityFromDimensions($width, $height, $unitShortName, $unitName)
{
    if (!$width || !$height || $width <= 0 || $height <= 0) {
        return 0;
    }

    $width = (float) $width;
    $height = (float) $height;

    // Логика расчета на основе единиц измерения - ВСЕ округляем до 2 знаков
    if ($unitShortName === 'м²' || $unitName === 'Квадратный метр') {
        return round($width * $height, 2);
    } elseif ($unitShortName === 'м' || $unitName === 'Метр') {
        return round(2 * $width + 2 * $height, 2);
    } elseif ($unitShortName === 'л' || $unitName === 'Литр') {
        return round($width * $height, 2);
    } elseif (
        $unitShortName === 'кг' || $unitName === 'Килограмм' ||
        $unitShortName === 'г' || $unitName === 'Грамм'
    ) {
        return round($width * $height, 2);
    } elseif ($unitShortName === 'шт' || $unitName === 'Штука') {
        return round($width * $height, 2);
    } elseif (
        $unitShortName === 'уп' || $unitName === 'Упаковка' ||
        $unitShortName === 'кор' || $unitName === 'Коробка' ||
        $unitShortName === 'пал' || $unitName === 'Паллета' ||
        $unitShortName === 'комп' || $unitName === 'Комплект' ||
        $unitShortName === 'рул' || $unitName === 'Рулон'
    ) {
        return round($width * $height, 2);
    } else {
        return round($width * $height, 2);
    }
}

// Создаем backup таблиц
echo "1. Создание backup таблиц...\n";
$timestamp = date('Y-m-d_H-i-s');

try {
    DB::statement("CREATE TABLE order_products_backup_{$timestamp} AS SELECT * FROM order_products");
    echo "   ✓ order_products_backup_{$timestamp} создана\n";

    DB::statement("CREATE TABLE orders_backup_{$timestamp} AS SELECT * FROM orders");
    echo "   ✓ orders_backup_{$timestamp} создана\n";

    DB::statement("CREATE TABLE transactions_backup_{$timestamp} AS SELECT * FROM transactions");
    echo "   ✓ transactions_backup_{$timestamp} создана\n";
} catch (\Exception $e) {
    echo "   ✗ Ошибка создания backup: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// Начинаем транзакцию
DB::beginTransaction();

try {
    // 2. Пересчитываем quantity для order_products с width и height
    echo "2. Пересчет quantity в order_products...\n";
    $productsWithDimensions = OrderProduct::with('product.unit')
        ->whereNotNull('width')
        ->whereNotNull('height')
        ->where('width', '>', 0)
        ->where('height', '>', 0)
        ->get();

    $updatedCount = 0;
    foreach ($productsWithDimensions as $op) {
        $oldQuantity = $op->quantity;
        $unitShortName = $op->product->unit->short_name ?? '';
        $unitName = $op->product->unit->name ?? '';
        $newQuantity = calculateQuantityFromDimensions($op->width, $op->height, $unitShortName, $unitName);

        if (abs($oldQuantity - $newQuantity) > 0.001) { // Если есть разница больше 0.001
            $op->quantity = $newQuantity;
            $op->save();
            $updatedCount++;

            if ($updatedCount <= 10) { // Показываем первые 10 изменений
                echo "   OrderProduct #{$op->id}: {$oldQuantity} → {$newQuantity} (разница: " . ($newQuantity - $oldQuantity) . ")\n";
            }
        }
    }
    echo "   ✓ Обновлено order_products: {$updatedCount}\n\n";

    // 3. Пересчитываем sums для order_temp_products (если есть width и height)
    echo "3. Пересчет quantity в order_temp_products...\n";
    $tempProducts = OrderTempProduct::with('unit')
        ->whereNotNull('width')
        ->whereNotNull('height')
        ->where('width', '>', 0)
        ->where('height', '>', 0)
        ->get();

    $tempUpdatedCount = 0;
    foreach ($tempProducts as $tp) {
        $oldQuantity = $tp->quantity;
        $unitShortName = $tp->unit->short_name ?? '';
        $unitName = $tp->unit->name ?? '';
        $newQuantity = calculateQuantityFromDimensions($tp->width, $tp->height, $unitShortName, $unitName);

        if (abs($oldQuantity - $newQuantity) > 0.001) {
            $tp->quantity = $newQuantity;
            $tp->save();
            $tempUpdatedCount++;

            if ($tempUpdatedCount <= 10) {
                echo "   OrderTempProduct #{$tp->id}: {$oldQuantity} → {$newQuantity} (разница: " . ($newQuantity - $oldQuantity) . ")\n";
            }
        }
    }
    echo "   ✓ Обновлено order_temp_products: {$tempUpdatedCount}\n\n";

    // 4. Пересчитываем orders.price для всех заказов
    echo "4. Пересчет сумм заказов (orders.price)...\n";
    $ordersUpdated = 0;
    $orders = Order::all();

    foreach ($orders as $order) {
        // Суммируем обычные товары
        $orderProductsPrice = OrderProduct::where('order_id', $order->id)
            ->select(DB::raw('SUM(quantity * price) as total'))
            ->value('total') ?? 0;

        // Суммируем временные товары
        $tempProductsPrice = OrderTempProduct::where('order_id', $order->id)
            ->select(DB::raw('SUM(quantity * price) as total'))
            ->value('total') ?? 0;

        $newPrice = $orderProductsPrice + $tempProductsPrice;
        $oldPrice = $order->price;

        if (abs($oldPrice - $newPrice) > 0.001) {
            $order->price = $newPrice;
            $order->save();
            $ordersUpdated++;

            if ($ordersUpdated <= 10) {
                echo "   Order #{$order->id}: {$oldPrice} → {$newPrice} (разница: " . ($newPrice - $oldPrice) . ")\n";
            }
        }
    }
    echo "   ✓ Обновлено заказов: {$ordersUpdated}\n\n";

    // 5. Пересчитываем суммы транзакций заказов
    echo "5. Пересчет сумм транзакций заказов...\n";
    $transactionsUpdated = 0;

    // Получаем все заказы с клиентами
    $ordersWithClients = Order::whereNotNull('client_id')->get();

    foreach ($ordersWithClients as $order) {
        // Находим автоматическую долговую транзакцию заказа
        $orderTransaction = Transaction::where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->where('type', 1)
            ->where('is_debt', true)
            ->first();

        if ($orderTransaction) {
            $total_price = $order->price - $order->discount;
            $oldAmount = $orderTransaction->amount;

            if (abs($oldAmount - $total_price) > 0.001) {
                $orderTransaction->amount = $total_price;
                $orderTransaction->orig_amount = $total_price;
                $orderTransaction->save();
                $transactionsUpdated++;

                if ($transactionsUpdated <= 10) {
                    echo "   Transaction #{$orderTransaction->id} (Order #{$order->id}): {$oldAmount} → {$total_price}\n";
                }
            }
        }
    }
    echo "   ✓ Обновлено транзакций: {$transactionsUpdated}\n\n";

    // 6. Пересчитываем балансы всех клиентов
    echo "6. Пересчет балансов клиентов...\n";
    $clientsUpdated = 0;

    $clients = Client::all();
    foreach ($clients as $client) {
        // Пересчитываем баланс из всех транзакций
        $balance = Transaction::where('client_id', $client->id)
            ->select(DB::raw('SUM(
                CASE
                    WHEN is_debt = 1 AND type = 1 THEN amount
                    WHEN is_debt = 1 AND type = 0 THEN -amount
                    WHEN is_debt = 0 AND type = 1 THEN -amount
                    WHEN is_debt = 0 AND type = 0 THEN amount
                END
            ) as balance'))
            ->value('balance') ?? 0;

        $oldBalance = $client->balance;

        if (abs($oldBalance - $balance) > 0.001) {
            $client->balance = $balance;
            $client->save();
            $clientsUpdated++;

            if ($clientsUpdated <= 10) {
                echo "   Client #{$client->id}: {$oldBalance} → {$balance} (разница: " . ($balance - $oldBalance) . ")\n";
            }
        }
    }
    echo "   ✓ Обновлено клиентов: {$clientsUpdated}\n\n";

    // Подтверждаем изменения
    DB::commit();

    echo "========================================\n";
    echo "✓ ВСЕ ИЗМЕНЕНИЯ ПРИМЕНЕНЫ УСПЕШНО\n";
    echo "========================================\n\n";

    echo "Итоги:\n";
    echo "  - OrderProducts обновлено: {$updatedCount}\n";
    echo "  - OrderTempProducts обновлено: {$tempUpdatedCount}\n";
    echo "  - Orders обновлено: {$ordersUpdated}\n";
    echo "  - Transactions обновлено: {$transactionsUpdated}\n";
    echo "  - Clients обновлено: {$clientsUpdated}\n\n";

    echo "Backup таблицы (для отката):\n";
    echo "  - order_products_backup_{$timestamp}\n";
    echo "  - orders_backup_{$timestamp}\n";
    echo "  - transactions_backup_{$timestamp}\n\n";

    echo "Для отката изменений выполните SQL:\n";
    echo "  TRUNCATE order_products; INSERT INTO order_products SELECT * FROM order_products_backup_{$timestamp};\n";
    echo "  TRUNCATE orders; INSERT INTO orders SELECT * FROM orders_backup_{$timestamp};\n";
    echo "  TRUNCATE transactions; INSERT INTO transactions SELECT * FROM transactions_backup_{$timestamp};\n";
    echo "  TRUNCATE clients; INSERT INTO clients SELECT * FROM (SELECT * FROM clients_backup_{$timestamp}) as backup;\n\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n❌ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Все изменения откачены.\n\n";
    exit(1);
}

