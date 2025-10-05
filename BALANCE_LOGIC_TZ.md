# ТЗ: Логика расчета баланса клиента

## Обзор изменений

Рефакторинг системы расчета баланса клиента для упрощения логики и централизации всех финансовых операций в таблице `transactions`.

## Исправление проблемы с временными зонами в фильтрах

### Проблема
Фильтр "за сегодня" в транзакциях работал некорректно из-за использования `whereDate()` с `now()->toDateString()`, что приводило к проблемам с временными зонами. Когда пользователь находился в другой временной зоне, транзакции могли не отображаться в фильтре "сегодня".

### Решение
Заменили `whereDate()` на `whereBetween()` с точными временными границами дня:
- `now()->startOfDay()->toDateTimeString()` - начало дня
- `now()->endOfDay()->toDateTimeString()` - конец дня

### Измененные файлы
- `app/Repositories/TransactionsRepository.php` - фильтры транзакций
- `app/Http/Controllers/DashboardController.php` - статистика за сегодня
- `app/Repositories/SalesRepository.php` - фильтры продаж
- `app/Repositories/ProjectsRepository.php` - фильтры проектов  
- `app/Repositories/OrdersRepository.php` - фильтры заказов
- `app/Repositories/InvoicesRepository.php` - фильтры счетов

### Технические детали
Вместо:
```php
->whereDate('transactions.date', '=', now()->toDateString())
```

Используется:
```php
->whereBetween('transactions.date', [
    now()->startOfDay()->toDateTimeString(),
    now()->endOfDay()->toDateTimeString()
])
```

Это обеспечивает корректную работу фильтров независимо от временной зоны пользователя.

## Текущая проблема

Сейчас баланс клиента рассчитывается из нескольких источников:
- `transactions` (операции с кассой)
- `sales` (продажи в долг, где `cash_id = null`)
- `orders` (заказы в долг, где `cash_id = null`)
- `warehouse_receipts` (оприходования в долг, где `cash_id = null`)

Это усложняет код и может приводить к ошибкам.

## Новая архитектура

### Принцип
**Все финансовые операции должны создавать записи в таблице `transactions`**

### Логика полей

#### `is_debt` (boolean)
- `true` - долговая операция (касса НЕ меняется, только баланс клиента)
- `false` - обычная операция (касса меняется + баланс клиента)

#### `cash_id`
- Заполняется всегда (даже для долгов)
- Показывает, через какую кассу проходит операция
- При `is_debt = true` касса не меняется, но поле заполнено для будущих операций (оплата/списание долга)

## Миграции

### 1. Добавление поля в transactions
```php
Schema::table('transactions', function (Blueprint $table) {
    $table->boolean('is_debt')->default(false)->after('type');
});
```

### 2. Добавление morphable связи
```php
Schema::table('transactions', function (Blueprint $table) {
    $table->string('source_type')->nullable()->after('is_debt');
    $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
});
```

### 3. Удаление дублирующихся полей из sales
```php
Schema::table('sales', function (Blueprint $table) {
    $table->dropColumn([
        'total_price',    // → transactions.amount
        'currency_id',    // → transactions.currency_id
        'date',          // → transactions.date
        'note',          // → transactions.note
        'transaction_id' // → transactions.source_id
    ]);
});
```

### 4. Удаление дублирующихся полей из orders
```php
Schema::table('orders', function (Blueprint $table) {
    $table->dropColumn([
        'total_price',    // → transactions.amount
        'cash_id',        // → transactions.cash_id
        'date',          // → transactions.date
        'note',          // → transactions.note
        'price',         // остается в orders (цена без скидки)
        'discount'       // остается в orders (скидка)
    ]);
});
```

### 5. Удаление таблицы order_transactions
```php
Schema::dropIfExists('order_transactions');
```

### 6. Добавление индексов для производительности
```php
Schema::table('transactions', function (Blueprint $table) {
    // Для расчета баланса клиента
    $table->index(['client_id', 'source_type', 'source_id'], 'idx_transactions_client_source');
    
    // Для поиска транзакций по источнику
    $table->index(['source_type', 'source_id'], 'idx_transactions_source');
    
    // Для фильтрации долговых операций
    $table->index(['is_debt'], 'idx_transactions_is_debt');
});
```

## Логика создания транзакций

### Продажи

#### Продажа за наличные
```php
Transaction::create([
    'client_id' => $sale->client_id,
    'amount' => $sale->total_price,
    'type' => 1, // доход
    'is_debt' => false, // не долг
    'cash_id' => $cashId,
    'source_type' => Sale::class,
    'source_id' => $sale->id,
]);
```

#### Продажа в долг
```php
Transaction::create([
    'client_id' => $sale->client_id,
    'amount' => $sale->total_price,
    'type' => 0, // расход для клиента (долг)
    'is_debt' => true, // это долг
    'cash_id' => $cashId, // касса указана, но не меняется
    'source_type' => Sale::class,
    'source_id' => $sale->id,
]);
```

### Заказы

#### Заказ за наличные
```php
Transaction::create([
    'client_id' => $order->client_id,
    'amount' => $order->total_price,
    'type' => 1, // доход
    'is_debt' => false, // не долг
    'cash_id' => $cashId,
    'source_type' => Order::class,
    'source_id' => $order->id,
]);
```

#### Заказ в долг
```php
Transaction::create([
    'client_id' => $order->client_id,
    'amount' => $order->total_price,
    'type' => 0, // расход для клиента (долг)
    'is_debt' => true, // это долг
    'cash_id' => $cashId, // касса указана, но не меняется
    'source_type' => Order::class,
    'source_id' => $order->id,
]);
```

**Важно:** После удаления таблицы `order_transactions` заказы будут использовать только morphable связи. Это означает, что **один заказ = одна транзакция**. Для частичных оплат нужно будет создавать отдельные транзакции с разными суммами.

### Оприходования

#### Оприходование за наличные
```php
Transaction::create([
    'client_id' => $receipt->supplier_id,
    'amount' => $receipt->amount,
    'type' => 0, // расход (мы платим поставщику)
    'is_debt' => false, // не долг
    'cash_id' => $cashId,
    'source_type' => WhReceipt::class,
    'source_id' => $receipt->id,
]);
```

#### Оприходование в долг
```php
Transaction::create([
    'client_id' => $receipt->supplier_id,
    'amount' => $receipt->amount,
    'type' => 0, // расход (мы должны поставщику)
    'is_debt' => true, // это долг
    'cash_id' => $cashId, // касса указана, но не меняется
    'source_type' => WhReceipt::class,
    'source_id' => $receipt->id,
]);
```

## Операции с долгами

### Оплата долга
```php
// Обновляем существующую транзакцию
$transaction->update([
    'is_debt' => false, // больше не долг
]);
// Касса увеличивается на сумму долга
```

### Списание долга
```php
// Обновляем существующую транзакцию
$transaction->update([
    'type' => 1, // меняем тип на доход
    'is_debt' => false, // больше не долг
]);
// Касса увеличивается на сумму долга
```

## Расчет баланса

### Новый метод расчета
```php
public function calculateBalance($clientId)
{
    $transactions = Transaction::where('client_id', $clientId)->get();
    
    $balance = 0;
    foreach ($transactions as $transaction) {
        if ($transaction->type == 1) {
            // Доход: клиент нам платит - увеличиваем баланс
            $balance += $transaction->amount;
        } else {
            // Расход: мы клиенту платим - уменьшаем баланс
            $balance -= $transaction->amount;
        }
    }
    
    return $balance;
}
```

### Обновление баланса кассы
```php
public function updateCashBalance($transaction)
{
    if ($transaction->is_debt) {
        // Долг - касса НЕ меняется
        return;
    }
    
    // Обычная транзакция - касса меняется
    $cash = CashRegister::find($transaction->cash_id);
    if ($transaction->type == 1) {
        $cash->balance += $transaction->amount; // доход
    } else {
        $cash->balance -= $transaction->amount; // расход
    }
    $cash->save();
}
```

## Изменения в моделях

### Transaction
```php
class Transaction extends Model
{
    protected $fillable = [
        // ... существующие поля
        'is_debt',
        'source_type',
        'source_id',
    ];
    
    public function source()
    {
        return $this->morphTo();
    }
    
    protected static function booted()
    {
        static::created(function ($transaction) {
            // Обновляем баланс клиента
            $this->updateClientBalance($transaction);
            
            // Обновляем баланс кассы (если не долг)
            $this->updateCashBalance($transaction);
        });
    }
}
```

### Sale, Order, WhReceipt
```php
// В каждой модели добавляем связь
public function transactions()
{
    return $this->morphMany(Transaction::class, 'source');
}
```

### Order (упрощение после удаления order_transactions)
```php
// Удаляем старую связь многие-ко-многим
// public function transactions()
// {
//     return $this->belongsToMany(Transaction::class, 'order_transactions', 'order_id', 'transaction_id');
// }

// Оставляем только morphable связь
public function transactions()
{
    return $this->morphMany(Transaction::class, 'source');
}
```

## Миграция существующих данных

### Скрипт миграции
```php
// 1. Создаем транзакции для существующих долговых операций
$sales = Sale::whereNull('cash_id')->get();
foreach ($sales as $sale) {
    Transaction::create([
        'client_id' => $sale->client_id,
        'amount' => $sale->total_price,
        'type' => 0,
        'is_debt' => true,
        'cash_id' => null, // или дефолтная касса
        'source_type' => Sale::class,
        'source_id' => $sale->id,
        'date' => $sale->date,
        'note' => $sale->note,
    ]);
}

// Аналогично для orders и warehouse_receipts
```

## Преимущества новой архитектуры

1. **Единая точка расчета** - баланс только из `transactions`
2. **Упрощение кода** - не нужно смотреть в разные таблицы
3. **Консистентность** - все операции в одном месте
4. **Гибкость** - легко добавлять новые типы операций
5. **Отчетность** - проще строить аналитику

## План реализации

1. ✅ Создать миграцию для добавления полей в `transactions`
2. ✅ Обновить модели для поддержки morphable связей
3. ✅ Изменить логику создания операций в репозиториях
   - ✅ SalesRepository - обновлен для новой архитектуры
   - ✅ OrdersRepository - обновлен для новой архитектуры  
   - ✅ WarehouseReceiptRepository - обновлен для новой архитектуры
4. ✅ Создать скрипт миграции существующих данных
5. ✅ Обновить методы расчета баланса
6. ✅ Обновить расчет баланса проекта для новой архитектуры
7. ✅ Протестировать новую логику
8. ✅ Удалить старую команду `RecalculateClientBalances`

## Файлы для изменения

### Миграции
- `database/migrations/` - новые миграции для добавления полей в transactions
- `database/migrations/` - миграция для удаления полей из sales
- `database/migrations/` - миграция для удаления таблицы order_transactions

### Модели
- `app/Models/Transaction.php` - добавление полей и morphable связей
- `app/Models/Sale.php` - добавление morphable связи, удаление старых полей
- `app/Models/Order.php` - замена связи многие-ко-многим на morphable
- `app/Models/WhReceipt.php` - добавление morphable связи
- `app/Models/OrderTransaction.php` - удалить файл

### Репозитории
- `app/Repositories/SalesRepository.php` - создание транзакций для долгов, удаление дублирующихся полей
- `app/Repositories/OrdersRepository.php` - создание транзакций для долгов, удаление логики order_transactions
- `app/Repositories/WarehouseReceiptRepository.php` - создание транзакций для долгов
- `app/Repositories/ClientsRepository.php` - новый метод расчета баланса
- `app/Repositories/TransactionsRepository.php` - удаление логики order_transactions

### Контроллеры
- `app/Http/Controllers/Api/OrderTransactionController.php` - удалить файл
- `app/Http/Controllers/Api/SaleController.php` - обновить для работы без дублирующихся полей

### Маршруты
- `routes/api.php` - удалить маршруты для order_transactions

### Тесты
- `tests/Feature/OrderDeletionTest.php` - обновить тесты
- `app/Console/Commands/RecalculateClientBalances.php` - удалить

## Тестирование

1. Создать тестовые данные с долговыми операциями
2. Запустить миграцию данных
3. Проверить корректность расчета баланса
4. Проверить работу операций с долгами (оплата/списание)
5. Проверить влияние на баланс кассы
