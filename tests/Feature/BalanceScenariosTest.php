<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Sale;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use App\Models\OrderStatus;
use App\Repositories\TransactionsRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BalanceScenariosTest extends TestCase
{
    protected $testData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->testData = [
            'client' => null,
            'sales' => [],
            'orders' => [],
            'transactions' => [],
            'receipts' => [],
        ];
    }

    protected function tearDown(): void
    {
        // Очистка тестовых данных
        if (!empty($this->testData['client'])) {
            $clientId = $this->testData['client']->id;
            Transaction::where('client_id', $clientId)->delete();
            ClientBalance::where('client_id', $clientId)->delete();
            Sale::where('client_id', $clientId)->delete();
            Order::where('client_id', $clientId)->delete();
            WhReceipt::where('supplier_id', $clientId)->delete();
            Client::where('id', $clientId)->delete();
        }
        parent::tearDown();
    }

    /**
     * Проверка баланса клиента и кассы
     */
    protected function checkBalance($clientId, $cashRegisterId, $expectedBalance, $expectedCashBalance, $step)
    {
        // Баланс из таблицы clients (используется в репозитории)
        $client = Client::find($clientId);
        $clientBalanceValue = $client ? $client->balance : 0;

        // Баланс из таблицы client_balances (если есть)
        $clientBalanceRecord = ClientBalance::where('client_id', $clientId)->first();
        $clientBalanceRecordValue = $clientBalanceRecord ? $clientBalanceRecord->balance : 0;

        // Баланс через SQL (только долговые транзакции)
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

        // Долговые транзакции для сравнения
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

        $sqlBalanceValue = $sqlBalance ? $sqlBalance->balance_amount : 0;
        $debtTransactionsValue = $debtTransactions ? $debtTransactions->total : 0;

        $tolerance = 0.01; // допуск для float

        // clients.balance должен совпадать с долговыми транзакциями
        // Баланс клиента обновляется ТОЛЬКО для долговых транзакций (is_debt=true)
        // Для не долговых транзакций баланс клиента не должен изменяться
        // Поэтому проверяем только долговые транзакции
        $this->assertLessThanOrEqual(
            $tolerance,
            abs($clientBalanceValue - $debtTransactionsValue),
            "{$step}: clients.balance ({$clientBalanceValue}) не совпадает с суммой долговых транзакций ({$debtTransactionsValue})"
        );

        // SQL должен совпадать с ожидаемым
        $this->assertLessThanOrEqual(
            $tolerance,
            abs($sqlBalanceValue - $expectedBalance),
            "{$step}: SQL расчет баланса клиента ({$sqlBalanceValue}) не совпадает с ожидаемым ({$expectedBalance})"
        );

        // Баланс кассы должен совпадать с ожидаемым
        $this->assertLessThanOrEqual(
            $tolerance,
            abs($cashBalanceValue - $expectedCashBalance),
            "{$step}: Баланс кассы ({$cashBalanceValue}) не совпадает с ожидаемым ({$expectedCashBalance})"
        );
    }

    public function test_client_balance_scenarios()
    {
        DB::beginTransaction();

        try {
            // Получаем необходимые данные
            $defaultCurrency = Currency::where('is_default', true)->first();
            $cashRegister = CashRegister::first();
            $warehouse = Warehouse::first();
            $product = Product::where('type', 0)->first(); // Услуга (type=0) - бесконечный сток
            $user = User::first();
            $category = Category::first();
            $orderStatus = OrderStatus::first();

            if (!$defaultCurrency || !$cashRegister || !$warehouse || !$product || !$user) {
                $this->markTestSkipped('Не найдены необходимые данные в БД');
            }

            // Если нет категории или статуса заказа - создаем временные
            if (!$category) {
                $category = Category::create([
                    'name' => 'TEST_CATEGORY',
                    'user_id' => $user->id,
                ]);
            }

            if (!$orderStatus) {
                $orderStatus = OrderStatus::create([
                    'name' => 'TEST_STATUS',
                    'category_id' => 1,
                ]);
            }

            // Создаем тестового клиента
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
            $uniquePhone = '+999' . time();
            $client->phones()->create(['phone' => $uniquePhone]);

            // Создаем баланс
            ClientBalance::create(['client_id' => $client->id, 'balance' => 0]);

            $this->testData['client'] = $client;

            // Запоминаем начальный баланс кассы
            $initialCashBalance = $cashRegister->balance;
            $currentBalance = 0; // Баланс клиента (только долговые транзакции)
            $currentCashBalance = $initialCashBalance; // Баланс кассы

            $txRepo = new TransactionsRepository();

            // 1. ПРОДАЖА В КАССУ (type=1, is_debt=false)
            // В SalesRepository для продажи в кассу создаются ДВЕ транзакции:
            // 1. Долговая (is_debt=true) - увеличивает баланс клиента
            // 2. Приходная (is_debt=false) - увеличивает кассу
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
            // Долговая транзакция (для баланса клиента)
            $txRepo->createItem([
                'client_id' => $client->id,
                'amount' => $saleAmount,
                'orig_amount' => $saleAmount,
                'type' => 1, // доход
                'is_debt' => true, // долговая - увеличивает баланс клиента
                'cash_id' => $cashRegister->id,
                'currency_id' => $defaultCurrency->id,
                'category_id' => 1,
                'source_type' => Sale::class,
                'source_id' => $sale1->id,
                'date' => now(),
                'user_id' => $user->id,
                'project_id' => null,
            ]);
            // Приходная транзакция (для кассы)
            $txRepo->createItem([
                'client_id' => $client->id,
                'amount' => $saleAmount,
                'orig_amount' => $saleAmount,
                'type' => 1, // доход
                'is_debt' => false, // приходная - увеличивает кассу, НЕ меняет баланс клиента
                'cash_id' => $cashRegister->id,
                'currency_id' => $defaultCurrency->id,
                'category_id' => 1,
                'source_type' => Sale::class,
                'source_id' => $sale1->id,
                'date' => now(),
                'user_id' => $user->id,
                'project_id' => null,
            ]);
            $this->testData['sales'][] = $sale1->id;
            $currentBalance += $saleAmount; // Клиент +100 (долговая транзакция)
            $currentCashBalance += $saleAmount; // Касса +100 (приходная транзакция)
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Продажа в кассу');

            // 2. ПРОДАЖА В ДОЛГ (type=1, is_debt=true)
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
            $txRepo->createItem([
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
                'project_id' => null,
            ]);
            $this->testData['sales'][] = $sale2->id;
            $currentBalance += $saleAmount; // Клиент +150
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Продажа в долг');

            // 3. ЗАКАЗ НЕОПЛАЧЕННЫЙ (200.00)
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
            $txRepo->createItem([
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
                'project_id' => null,
            ]);
            $this->testData['orders'][] = $order1->id;
            $currentBalance += $orderAmount; // Клиент +200
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Заказ неоплаченный');

            // 4. ЗАКАЗ С ЧАСТИЧНОЙ ОПЛАТОЙ (250.00, оплачено 100.00)
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
            $txRepo->createItem([
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
                'project_id' => null,
            ]);
            // Клиент оплатил 100 из 250 - создаем транзакцию оплаты долга
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
            $this->testData['orders'][] = $order2->id;
            $currentBalance += $orderAmount; // Клиент +250 (автоматическая транзакция заказа)
            $currentBalance -= $paymentAmount; // Клиент -100 (оплата, type=0, is_debt=true)
            $currentCashBalance += $paymentAmount; // Касса +100 (СПЕЦИАЛЬНО для оплат заказов!)
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Заказ с частичной оплатой');

            // 5. ИЗМЕНЕНИЕ СУММЫ ЗАКАЗА (уменьшаем скидку, сумма с 250 на 300)
            $newOrderTotal = 300.00;
            $order2->price = 325.00;
            $order2->discount = 25.00; // total = 325 - 25 = 300
            $order2->save();

            // Обновляем автоматическую транзакцию заказа
            $orderAutoTransaction = Transaction::where('source_type', Order::class)
                ->where('source_id', $order2->id)
                ->where('type', 1)
                ->where('is_debt', true)
                ->first();

            if ($orderAutoTransaction) {
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
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Изменение суммы заказа');

            // 6. ТРАНЗАКЦИЯ ДОХОД В КАССУ (type=1, is_debt=false)
            $trAmount = 50.00;
            $tr1Id = $txRepo->createItem([
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
                'project_id' => null,
            ], true);
            $this->testData['transactions'][] = $tr1Id;
            $currentCashBalance += $trAmount; // Касса +50
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция доход в кассу');

            // 7. ТРАНЗАКЦИЯ ДОХОД В ДОЛГ (type=1, is_debt=true)
            $trAmount = 75.00;
            $tr2Id = $txRepo->createItem([
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
                'project_id' => null,
            ], true);
            $this->testData['transactions'][] = $tr2Id;
            $currentBalance += $trAmount; // Клиент +75
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция доход в долг');

            // 8. ТРАНЗАКЦИЯ РАСХОД В КАССУ (type=0, is_debt=false)
            $trAmount = 30.00;
            $tr3Id = $txRepo->createItem([
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
                'project_id' => null,
            ], true);
            $this->testData['transactions'][] = $tr3Id;
            $currentCashBalance -= $trAmount; // Касса -30
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция расход в кассу');

            // 9. ТРАНЗАКЦИЯ РАСХОД В ДОЛГ (type=0, is_debt=true)
            $trAmount = 45.00;
            $tr4Id = $txRepo->createItem([
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
                'project_id' => null,
            ], true);
            $this->testData['transactions'][] = $tr4Id;
            $currentBalance -= $trAmount; // Клиент -45
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Транзакция расход в долг');

            // 10. ОПРИХОДОВАНИЕ В КАССУ (type=0, is_debt=false)
            $receiptAmount = 120.00;
            $receipt1 = WhReceipt::create([
                'supplier_id' => $client->id,
                'warehouse_id' => $warehouse->id,
                'cash_id' => $cashRegister->id,
                'user_id' => $user->id,
                'amount' => $receiptAmount,
                'date' => now(),
            ]);
            $txRepo->createItem([
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
                'project_id' => null,
            ]);
            $this->testData['receipts'][] = $receipt1->id;
            $currentCashBalance -= $receiptAmount; // Касса -120
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Оприходование в кассу');

            // 11. ОПРИХОДОВАНИЕ В ДОЛГ (type=0, is_debt=true)
            $receiptAmount = 180.00;
            $receipt2 = WhReceipt::create([
                'supplier_id' => $client->id,
                'warehouse_id' => $warehouse->id,
                'cash_id' => $cashRegister->id,
                'user_id' => $user->id,
                'amount' => $receiptAmount,
                'date' => now(),
            ]);
            $txRepo->createItem([
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
                'project_id' => null,
            ]);
            $this->testData['receipts'][] = $receipt2->id;
            $currentBalance -= $receiptAmount; // Клиент -180
            $this->checkBalance($client->id, $cashRegister->id, $currentBalance, $currentCashBalance, 'Оприходование в долг');

            // Итоговые проверки
            $this->assertEquals(
                round($currentBalance, 2),
                round(150.00 + 200.00 + 250.00 - 100.00 + 300.00 - 250.00 + 75.00 - 45.00 - 180.00, 2),
                'Итоговый баланс клиента неверный'
            );

            $this->assertEquals(
                round($currentCashBalance - $initialCashBalance, 2),
                round(100.00 + 100.00 + 50.00 - 30.00 - 120.00, 2),
                'Итоговое изменение баланса кассы неверное'
            );

            DB::rollBack(); // Откатываем транзакцию, чтобы не сохранять тестовые данные
        } catch (\Exception $e) {
            DB::rollBack();
            $this->fail("Ошибка при выполнении теста: {$e->getMessage()}\n{$e->getTraceAsString()}");
        }
    }
}


