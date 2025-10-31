# Система правил округления

## Обзор

Система округления позволяет настраивать правила округления сумм **на уровне компании** для разных контекстов операций. Это означает, что каждая компания может иметь свои индивидуальные правила для:
- **Заказов** (orders)
- **Оприходований** (receipts)  
- **Продаж** (sales)
- **Транзакций** (transactions)

## Основные концепции

### Контексты округления

Каждый контекст определяет, в каких операциях применяется правило:

- `orders` - расчет количеств при создании/редактировании заказов (order_products.quantity)
- `receipts` - суммы при оприходовании товаров (wh_receipts.amount)
- `sales` - суммы при продажах (sales.price, sales.discount, sales_products.price)
- `transactions` - суммы при создании/редактировании транзакций (transactions.amount)

### Направления округления

1. **standard** - стандартное округление по математическим правилам (2.5 → 3, 2.4 → 2)
2. **up** - всегда в большую сторону (2.1 → 3, 2.9 → 3)
3. **down** - всегда в меньшую сторону (2.1 → 2, 2.9 → 2)
4. **custom** - настраиваемый порог (например, 2.5 → 2, 2.6 → 3, если threshold = 0.6)

### Точность

Количество знаков после запятой: от 2 до 5. Система хранения данных поддерживает до 5 знаков.

## Реализация на бэкенде

### Модель и миграции

**Таблица**: `company_rounding_rules`
```
- company_id (FK) - компания
- context (VARCHAR) - контекст: orders/receipts/sales/transactions
- decimals (TINYINT 2-5) - количество знаков
- direction (VARCHAR) - направление: standard/up/down/custom
- custom_threshold (DECIMAL 3,2) - порог для custom (0.00-1.00)
```

**Модель**: `App\Models\CompanyRoundingRule`

### Сервис округления

**Сервис**: `App\Services\RoundingService`

Основной метод:
```php
roundForCompany(?int $companyId, string $context, float $value): float
```

**Константы контекстов**:
- `RoundingService::CONTEXT_ORDERS`
- `RoundingService::CONTEXT_RECEIPTS`
- `RoundingService::CONTEXT_SALES`
- `RoundingService::CONTEXT_TRANSACTIONS`

**Константы направлений**:
- `RoundingService::DIRECTION_STANDARD`
- `RoundingService::DIRECTION_UP`
- `RoundingService::DIRECTION_DOWN`
- `RoundingService::DIRECTION_CUSTOM`

### Применение в репозиториях

#### TransactionsRepository
```php
// При создании транзакции (строка 342-345)
$roundingService = new RoundingService();
$companyId = $this->getCurrentCompanyId();
$convertedAmount = $roundingService->roundForCompany(
    $companyId, 
    RoundingService::CONTEXT_TRANSACTIONS, 
    (float) $convertedAmount
);

// При обновлении транзакции (строка 672-677)
// аналогично
```

#### OrdersRepository
```php
// calculateQuantityFromDimensions - расчет количества по габаритам (строка 1314-1320)
$roundingService = new RoundingService();
$companyId = $this->getCurrentCompanyId();
return $roundingService->roundForCompany(
    $companyId, 
    RoundingService::CONTEXT_ORDERS, 
    (float) $raw
);
```

#### SalesRepository
```php
// При создании продажи (строка 297-306)
$roundingService = new RoundingService();
$companyId = $this->getCurrentCompanyId();
$price = $roundingService->roundForCompany($companyId, RoundingService::CONTEXT_SALES, (float) $price);
$discountCalc = $roundingService->roundForCompany($companyId, RoundingService::CONTEXT_SALES, (float) $discountCalc);
$totalPrice = $roundingService->roundForCompany($companyId, RoundingService::CONTEXT_SALES, (float) $totalPrice);

// Для цен продуктов в продаже (строка 325-335)
'price' => (new RoundingService())->roundForCompany(
    $this->getCurrentCompanyId(),
    RoundingService::CONTEXT_SALES,
    (float) CurrencyConverter::convert(...)
)
```

#### WarehouseReceiptRepository
```php
// При создании оприходования (строка 138-142)
$roundingService = new RoundingService();
$companyId = $this->getCurrentCompanyId();
$total_amount = $roundingService->roundForCompany(
    $companyId, 
    RoundingService::CONTEXT_RECEIPTS, 
    (float) $total_amount
);

// При обновлении оприходования (строка 283-287)
// аналогично
```

### API контроллер

**Контроллер**: `App\Http\Controllers\Api\CompanyRoundingRulesController`

**Эндпоинты**:
- `GET /api/company-rounding-rules` - получить все правила текущей компании
- `POST /api/company-rounding-rules` - создать/обновить правило

**Параметры POST**:
```json
{
  "context": "orders|receipts|sales|transactions",
  "decimals": 2-5,
  "direction": "standard|up|down|custom",
  "custom_threshold": 0.0-1.0 (обязателен только для direction=custom)
}
```

**Валидация**:
- context: обязателен, один из 4 контекстов
- decimals: обязателен, 2-5
- direction: обязателен, один из 4 направлений
- custom_threshold: опционален (null если direction != custom), 0.0-1.0

## Важные замечания

### Конвертация валют

Правила округления применяются **после** конвертации валют. Порядок:

1. Конвертация суммы из исходной валюты в валюту кассы/базовую валюту
2. Применение правил округления компании
3. Сохранение результата

### Обратная совместимость

**Старые записи не пересчитываются**. Правила округления применяются только к **новым операциям**. Если у компании меняются правила, существующие данные остаются без изменений.

### Удаление старой логики

**Удалено**: поле `cash_registers.is_rounding` и метод `CashRegister::roundAmount()`.

**Миграция**: `2025_10_30_000300_drop_is_rounding_from_cash_registers.php` удаляет колонку.

**Причина**: старая логика была на уровне кассы (глобально для всех операций), новая система более гибкая - на уровне компании с разными правилами для разных контекстов.

### Увеличение точности хранения

**Миграция**: `2025_10_30_000200_increase_precision_to_5.php`

Увеличена точность в БД до 5 знаков после запятой для:
- transactions: amount, orig_amount
- orders: price, discount
- order_products: quantity, price, discount
- sales: price, discount
- sales_products: price
- wh_receipts: amount
- wh_receipt_products: price
- product_prices: retail_price, wholesale_price, purchase_price

**Обновлены модели**: убраны касты `decimal:2`, заменены на `decimal:5`.

### Получение текущей компании

Во всех репозиториях используется единый подход:
```php
private function getCurrentCompanyId() {
    return request()->header('X-Company-ID');
}
```

Заголовок `X-Company-ID` автоматически передается фронтендом при каждом запросе на основе выбранной компании пользователя.

## Примеры использования

### Пример 1: Стандартное округление до 2 знаков

**Настройка**:
- context: transactions
- decimals: 2
- direction: standard

**Результат**:
- 123.456 → 123.46
- 123.454 → 123.45
- 123.455 → 123.46

### Пример 2: Округление вверх до целых

**Настройка**:
- context: orders
- decimals: 0
- direction: up

**Результат**:
- 123.1 → 124
- 123.9 → 124

### Пример 3: Кастомный порог

**Настройка**:
- context: sales
- decimals: 1
- direction: custom
- custom_threshold: 0.6

**Результат** (для 2.xy где xy - дробная часть):
- 2.59 → 2.5 (59% < 60%, вниз)
- 2.60 → 2.6 (60% >= 60%, вверх)
- 2.61 → 2.6 (вверх)

## Фронтенд

### Структура файлов

1. **API контроллер**: `front/src/api/CompanyRoundingRulesController.js`
2. **DTO**: `front/src/dto/CompanyRoundingRuleDto.js`
3. **Страница настроек**: `front/src/views/pages/settings/CompanyRoundingRulesPage.vue`

### UI настроек

Страница с настройками компании содержит:
- 4 секции (по одной на контекст)
- В каждой секции:
  - Выбор количества знаков (2-5)
  - Выбор направления (standard/up/down/custom)
  - При выборе custom: поле порога (0.0-1.0)

### Формат данных

**Загрузка**:
```javascript
GET /api/company-rounding-rules
Response: { data: [CompanyRoundingRuleDto] }
```

**Сохранение**:
```javascript
POST /api/company-rounding-rules
Body: {
  context: string,
  decimals: number,
  direction: string,
  custom_threshold?: number
}
Response: { rule: CompanyRoundingRuleDto }
```

## Тестирование

### Проверка контекстов

1. Создать заказ с товаром (габариты) → проверить quantity
2. Создать оприходование → проверить amount
3. Создать продажу → проверить price, discount
4. Создать транзакцию → проверить amount

### Проверка направлений

1. Установить decimals=1, direction=up → 2.1 → 2.1 (но 2.11 → 2.2 если decimals=2)
2. Установить direction=down → 2.9 → 2.9
3. Установить direction=custom, threshold=0.6 → проверить логику порога

### Проверка совместимости

1. Создать операцию с старыми правилами
2. Изменить правила компании
3. Создать новую операцию → должны применяться новые правила
4. Проверить старую операцию → не должна измениться

## Миграция

При развертывании выполнить:

```bash
php artisan migrate
```

Миграции выполнятся в порядке:
1. `2025_10_30_000100_create_company_rounding_rules_table.php` - создание таблицы правил
2. `2025_10_30_000200_increase_precision_to_5.php` - увеличение точности
3. `2025_10_30_000300_drop_is_rounding_from_cash_registers.php` - удаление старого поля

