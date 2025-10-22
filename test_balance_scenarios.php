<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\User;
use App\Repositories\TransactionsRepository;
use App\Repositories\OrdersRepository;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════\n";
echo "   ТЕСТИРОВАНИЕ ЛОГИКИ БАЛАНСА КЛИЕНТА\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$testData = [
    'client' => null,
    'sales' => [],
    'orders' => [],
    'transactions' => [],
    'receipts' => [],
];

try {
    DB::beginTransaction();

    // Получаем необходимые данные
    $defaultCurrency = Currency::where('is_default', true)->first();
    $cashRegister = CashRegister::first();
    $warehouse = Warehouse::first();
    $product = Product::where('type', 0)->first(); // Услуга (type=0) - бесконечный сток
    $user = User::first();
    $category = \App\Models\Category::first();
    $orderStatus = \App\Models\OrderStatus::first();

    if (!$defaultCurrency || !$cashRegister || !$warehouse || !$product || !$user) {
        throw new Exception("Не найдены необходимые данные в БД");
    }

    // Если нет категории или статуса заказа - создаем временные
    if (!$category) {
        $category = \App\Models\Category::create([
            'name' => 'TEST_CATEGORY',
            'user_id' => $user->id,
        ]);
    }

    if (!$orderStatus) {
        $orderStatus = \App\Models\OrderStatus::create([
            'name' => 'TEST_STATUS',
            'category_id' => 1,
        ]);
    }

    echo "📋 Используемые данные:\n";
    echo "  - Валюта: {$defaultCurrency->name} ({$defaultCurrency->symbol})\n";
    echo "  - Касса: {$cashRegister->name}\n";
    echo "  - Склад: {$warehouse->name}\n";
    echo "  - Товар: {$product->name}\n\n";

    // 1. Создаем тестового клиента
    echo "1️⃣  Создание тестового клиента...\n";

    // Получаем company_id текущего пользователя
    $companyId = $user->companies()->first()->id ?? null;

    $client = Client::create([
        'first_name' => 'TEST',
        'last_name' => 'CLIENT',
        'client_type' => 'individual',
        'status' => true,
        'is_supplier' => true,
        'user_id' => $user->id,
        'company_id' => $companyId,
        'discount' => 0,
        'discount_type' => 'fixed',
    ]);

    // Создаем телефон для клиента (уникальный с timestamp)
    $uniquePhone = '+999' . time(); // Используем timestamp для уникальности
    $client->phones()->create(['phone' => $uniquePhone]);

    // Создаем баланс
    ClientBalance::create(['client_id' => $client->id, 'balance' => 0]);

    $testData['client'] = $client;
    echo "   ✅ Клиент создан (ID: {$client->id})\n\n";

    // Запоминаем начальный баланс кассы
    $initialCashBalance = $cashRegister->balance;
    echo "💰 Начальный баланс кассы: " . number_format($initialCashBalance, 2) . "\n\n";

    // Функция для проверки баланса
    function checkBalance($clientId, $cashRegisterId, $expectedSqlBalance, $expectedCashBalance, $step) {
        // Баланс из таблицы (только транзакции, БЕЗ заказов)
        $dbBalance = ClientBalance::where('client_id', $clientId)->first();

        // Баланс через SQL (как в репозитории) - ТОЛЬКО долговые транзакции
        // Заказы теперь создают автоматические транзакции, поэтому НЕ считаем их отдельно
        $sqlBalance = DB::table('clients')
            ->where('id', $clientId)
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
                  AND t.is_debt = 1
            ) as balance_amount')
            ->first();

        // Долговые транзакции (только is_debt=true) для сравнения с client_balances
        $debtTransactions = DB::table('transactions')
            ->where('client_id', $clientId)
            ->where('is_debt', 1)
            ->selectRaw('
                SUM(CASE WHEN type = 1 THEN amount ELSE -amount END) as total
            ')
            ->first();

        // Баланс кассы
        $cashBalance = CashRegister::find($cashRegisterId);
        $cashBalanceValue = $cashBalance ? $cashBalance->balance : 0;

        $dbBalanceValue = $dbBalance ? $dbBalance->balance : 0;
        $sqlBalanceValue = $sqlBalance ? $sqlBalance->balance_amount : 0;
        $debtTransactionsValue = $debtTransactions ? $debtTransactions->total : 0;

        echo "   📊 Баланс после '{$step}':\n";
        echo "      👤 Клиент:\n";
        echo "         - client_balances (долговые транзакции): " . number_format($dbBalanceValue, 2) . "\n";
        echo "         - Долговые транзакции (проверка): " . number_format($debtTransactionsValue, 2) . "\n";
        echo "         - SQL (только долговые транзакции): " . number_format($sqlBalanceValue, 2) . "\n";
        echo "         - Ожидается: " . number_format($expectedSqlBalance, 2) . "\n";
        echo "      💰 Касса:\n";
        echo "         - Баланс кассы: " . number_format($cashBalanceValue, 2) . "\n";
        echo "         - Ожидается: " . number_format($expectedCashBalance, 2) . "\n";

        $tolerance = 0.01; // допуск для float

        // client_balances должен совпадать с долговыми транзакциями
        if (abs($dbBalanceValue - $debtTransactionsValue) > $tolerance) {
            echo "      ❌ ОШИБКА: client_balances не совпадает с суммой долговых транзакций!\n";
            return false;
        }

        // SQL должен совпадать с ожидаемым
        if (abs($sqlBalanceValue - $expectedSqlBalance) > $tolerance) {
            echo "      ❌ ОШИБКА: SQL расчет клиента не совпадает с ожиданием!\n";
            return false;
        }

        // Баланс кассы должен совпадать с ожидаемым
        if (abs($cashBalanceValue - $expectedCashBalance) > $tolerance) {
            echo "      ❌ ОШИБКА: Баланс кассы не совпадает с ожиданием!\n";
            return false;
        }

        echo "      ✅ Все балансы правильные!\n\n";
        return true;
    }

    $currentBalance = 0; // Баланс клиента (SQL = долговые транзакции + заказы)
    $currentCashBalance = $initialCashBalance; // Баланс кассы

    // 2. ПРОДАЖА В КАССУ (type=1, is_debt=false)
    echo "2️⃣  Продажа в кассу (100.00)...\n";
    $saleAmount = 100.00;
    $sale1 = Sale::create([
        'client_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'cash_id' => $cashRegister->id,
        'user_id' => $user->id,
        'price' => $saleAmount,
        'discount' => 0,
        'date' => now(),
    ]);
    Transaction::create([
        'client_id' => $client->id,
        'amount' => $saleAmount,
        'orig_amount' => $saleAmount,
        'type' => 1, // доход
        'is_debt' => false, // в кассу - баланс клиента НЕ меняется
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => Sale::class,
        'source_id' => $sale1->id,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['sales'][] = $sale1->id;
    // $currentBalance += 0; // Клиент не изменяется (is_debt=false - клиент уже заплатил)
    $currentCashBalance += $saleAmount; // Касса +100 (is_debt=false, type=1)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Продажа в кассу');

    // 3. ПРОДАЖА В ДОЛГ (type=1, is_debt=true)
    echo "3️⃣  Продажа в долг (150.00)...\n";
    $saleAmount = 150.00;
    $sale2 = Sale::create([
        'client_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'cash_id' => $cashRegister->id,
        'user_id' => $user->id,
        'price' => $saleAmount,
        'discount' => 0,
        'date' => now(),
    ]);
    Transaction::create([
        'client_id' => $client->id,
        'amount' => $saleAmount,
        'orig_amount' => $saleAmount,
        'type' => 1, // доход
        'is_debt' => true, // в долг
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => Sale::class,
        'source_id' => $sale2->id,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['sales'][] = $sale2->id;
    $currentBalance += $saleAmount; // Клиент +150
    // Касса НЕ изменяется (is_debt=true)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Продажа в долг');

    // 4. ЗАКАЗ НЕОПЛАЧЕННЫЙ (200.00)
    echo "4️⃣  Заказ неоплаченный (200.00)...\n";
    $orderAmount = 200.00;
    $order1 = Order::create([
        'client_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'cash_id' => $cashRegister->id,
        'user_id' => $user->id,
        'status_id' => $orderStatus->id,
        'category_id' => $category->id,
        'price' => $orderAmount,
        'discount' => 0,
        'date' => now(),
    ]);
    // Создаем автоматическую долговую транзакцию для заказа
    Transaction::create([
        'client_id' => $client->id,
        'amount' => $orderAmount,
        'orig_amount' => $orderAmount,
        'type' => 1, // доход (клиент должен)
        'is_debt' => true, // долг
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => Order::class,
        'source_id' => $order1->id,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['orders'][] = $order1->id;
    $currentBalance += $orderAmount; // Клиент +200 (is_debt=true)
    // Касса НЕ изменяется (is_debt=true)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Заказ неоплаченный');

    // 5. ЗАКАЗ С ЧАСТИЧНОЙ ОПЛАТОЙ (250.00, оплачено 100.00)
    echo "5️⃣  Заказ с частичной оплатой (250.00, оплата 100.00)...\n";
    $orderAmount = 250.00;
    $paymentAmount = 100.00;
    $order2 = Order::create([
        'client_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'cash_id' => $cashRegister->id,
        'user_id' => $user->id,
        'status_id' => $orderStatus->id,
        'category_id' => $category->id,
        'price' => $orderAmount,
        'discount' => 0,
        'date' => now(),
    ]);
    // Создаем автоматическую долговую транзакцию для заказа
    Transaction::create([
        'client_id' => $client->id,
        'amount' => $orderAmount,
        'orig_amount' => $orderAmount,
        'type' => 1, // доход (клиент должен)
        'is_debt' => true, // долг
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => Order::class,
        'source_id' => $order2->id,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    // Клиент оплатил 100 из 250 - создаем транзакцию оплаты долга
    // type=0 (расход для клиента = уменьшение долга)
    // is_debt=true (долговая операция)
    // source_type=Order (привязка к заказу)
    // СПЕЦИАЛЬНО: касса увеличивается для оплат заказов!
    $txRepo = new TransactionsRepository();
    $txRepo->createItem([
        'client_id' => $client->id,
        'amount' => $paymentAmount,
        'orig_amount' => $paymentAmount,
        'type' => 0, // расход для клиента (уменьшение долга)
        'is_debt' => true, // долговая операция
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => 'App\Models\Order',
        'source_id' => $order2->id,
        'date' => now(),
        'note' => 'Частичная оплата заказа',
        'user_id' => $user->id,
        'project_id' => null,
    ], true, false); // false - НЕ пропускать обновление баланса клиента!
    $testData['orders'][] = $order2->id;
    $currentBalance += $orderAmount; // Клиент +250 (автоматическая транзакция заказа)
    $currentBalance -= $paymentAmount; // Клиент -100 (оплата, type=0, is_debt=true)
    $currentCashBalance += $paymentAmount; // Касса +100 (СПЕЦИАЛЬНО для оплат заказов!)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Заказ с частичной оплатой');

    // 5.5. ИЗМЕНЕНИЕ СУММЫ ЗАКАЗА (уменьшаем скидку, сумма с 250 на 300)
    echo "5️⃣➕ Изменение суммы заказа #2 с 250 на 300 (уменьшили скидку)...\n";
    $newOrderTotal = 300.00;

    // Обновляем заказ напрямую (меняем только price/discount, без товаров)
    $order2->price = 325.00;
    $order2->discount = 25.00; // total = 325 - 25 = 300
    $order2->save();

    // Обновляем автоматическую транзакцию заказа вручную (как это делает OrdersRepository)
    $orderAutoTransaction = Transaction::where('source_type', Order::class)
        ->where('source_id', $order2->id)
        ->where('type', 1)
        ->where('is_debt', true)
        ->first();

    if ($orderAutoTransaction) {
        $txRepo = new TransactionsRepository();
        $txRepo->updateItem($orderAutoTransaction->id, [
            'amount' => $newOrderTotal,
            'orig_amount' => $newOrderTotal,
            'client_id' => $client->id,
            'project_id' => null,
            'cash_id' => $cashRegister->id,
            'category_id' => 1,
            'date' => now(),
            'note' => 'Изменена сумма заказа',
        ]);
    }

    // Разница в заказе: было 250, стало 300 → +50
    $currentBalance -= $orderAmount; // Откатываем старую сумму заказа -250
    $currentBalance += $newOrderTotal; // Применяем новую сумму +300
    // Итого: +50 к текущему балансу
    // Касса не меняется (изменение заказа не влияет на кассу)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Изменение суммы заказа');

    // 6. ТРАНЗАКЦИЯ ДОХОД В КАССУ (type=1, is_debt=false)
    echo "6️⃣  Транзакция доход в кассу (50.00)...\n";
    $trAmount = 50.00;
    $tr1 = Transaction::create([
        'client_id' => $client->id,
        'amount' => $trAmount,
        'orig_amount' => $trAmount,
        'type' => 1, // доход
        'is_debt' => false, // в кассу - баланс клиента НЕ меняется
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['transactions'][] = $tr1->id;
    // $currentBalance += 0; // Клиент не изменяется (is_debt=false)
    $currentCashBalance += $trAmount; // Касса +50 (is_debt=false, type=1)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция доход в кассу');

    // 7. ТРАНЗАКЦИЯ ДОХОД В ДОЛГ (type=1, is_debt=true)
    echo "7️⃣  Транзакция доход в долг (75.00)...\n";
    $trAmount = 75.00;
    $tr2 = Transaction::create([
        'client_id' => $client->id,
        'amount' => $trAmount,
        'orig_amount' => $trAmount,
        'type' => 1, // доход
        'is_debt' => true, // в долг
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['transactions'][] = $tr2->id;
    $currentBalance += $trAmount; // Клиент +75
    // Касса НЕ изменяется (is_debt=true)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция доход в долг');

    // 8. ТРАНЗАКЦИЯ РАСХОД В КАССУ (type=0, is_debt=false)
    echo "8️⃣  Транзакция расход в кассу (30.00)...\n";
    $trAmount = 30.00;
    $tr3 = Transaction::create([
        'client_id' => $client->id,
        'amount' => $trAmount,
        'orig_amount' => $trAmount,
        'type' => 0, // расход
        'is_debt' => false,
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['transactions'][] = $tr3->id;
    // $currentBalance += 0; // Клиент не изменяется (is_debt=false)
    $currentCashBalance -= $trAmount; // Касса -30 (is_debt=false, type=0)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция расход в кассу');

    // 9. ТРАНЗАКЦИЯ РАСХОД В ДОЛГ (type=0, is_debt=true)
    echo "9️⃣  Транзакция расход в долг (45.00)...\n";
    $trAmount = 45.00;
    $tr4 = Transaction::create([
        'client_id' => $client->id,
        'amount' => $trAmount,
        'orig_amount' => $trAmount,
        'type' => 0, // расход
        'is_debt' => true, // в долг
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['transactions'][] = $tr4->id;
    $currentBalance -= $trAmount; // Клиент -45 (is_debt=true - долг)
    // Касса НЕ изменяется (is_debt=true)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция расход в долг');

    // 10. ОПРИХОДОВАНИЕ В КАССУ (type=0, is_debt=false)
    echo "🔟 Оприходование в кассу (120.00)...\n";
    $receiptAmount = 120.00;
    $receipt1 = WhReceipt::create([
        'supplier_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'cash_id' => $cashRegister->id,
        'user_id' => $user->id,
        'amount' => $receiptAmount,
        'date' => now(),
    ]);
    Transaction::create([
        'client_id' => $client->id,
        'amount' => $receiptAmount,
        'orig_amount' => $receiptAmount,
        'type' => 0, // расход (мы платим поставщику)
        'is_debt' => false, // в кассу - баланс клиента НЕ меняется
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => WhReceipt::class,
        'source_id' => $receipt1->id,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['receipts'][] = $receipt1->id;
    // $currentBalance += 0; // Клиент не изменяется (is_debt=false - мы уже заплатили)
    $currentCashBalance -= $receiptAmount; // Касса -120 (is_debt=false, type=0)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Оприходование в кассу');

    // 11. ОПРИХОДОВАНИЕ В ДОЛГ (type=0, is_debt=true)
    echo "1️⃣1️⃣  Оприходование в долг (180.00)...\n";
    $receiptAmount = 180.00;
    $receipt2 = WhReceipt::create([
        'supplier_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'cash_id' => $cashRegister->id,
        'user_id' => $user->id,
        'amount' => $receiptAmount,
        'date' => now(),
    ]);
    Transaction::create([
        'client_id' => $client->id,
        'amount' => $receiptAmount,
        'orig_amount' => $receiptAmount,
        'type' => 0, // расход (мы должны поставщику)
        'is_debt' => true, // в долг
        'cash_id' => $cashRegister->id,
        'currency_id' => $defaultCurrency->id,
        'category_id' => 1,
        'source_type' => WhReceipt::class,
        'source_id' => $receipt2->id,
        'date' => now(),
        'user_id' => $user->id,
    ]);
    $testData['receipts'][] = $receipt2->id;
    $currentBalance -= $receiptAmount; // Клиент -180 (is_debt=true - долг)
    // Касса НЕ изменяется (is_debt=true)
    checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Оприходование в долг');

    echo "═══════════════════════════════════════════════════════════\n";
    echo "📊 ИТОГОВЫЙ БАЛАНС КЛИЕНТА: " . number_format($currentBalance, 2) . "\n";
    echo "📊 ИТОГОВЫЙ БАЛАНС КАССЫ: " . number_format($currentCashBalance - $initialCashBalance, 2) . " (изменение)\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    // ЗАКОММЕНТИРОВАНО: Оставляем тестовые данные для проверки в интерфейсе
    // DB::rollBack();

    // Сохраняем данные в БД
    DB::commit();

    echo "✅ Тестовые данные СОХРАНЕНЫ в БД для проверки!\n";
    echo "   Клиент ID: {$client->id}\n";
    echo "   Имя: TEST CLIENT\n";
    echo "   Телефон: {$uniquePhone}\n";
    echo "   Company ID: " . ($companyId ?? 'NULL') . "\n\n";
    echo "⚠️  ВАЖНО: После проверки удалите тестового клиента вручную!\n\n";

    echo "═══════════════════════════════════════════════════════════\n";
    echo "   ✅ ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
    echo "═══════════════════════════════════════════════════════════\n";

} catch (Exception $e) {
    DB::rollBack();
    echo "\n❌ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Трассировка:\n" . $e->getTraceAsString() . "\n";
}

