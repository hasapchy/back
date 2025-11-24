# План миграции на Form Request

## Анализ рисков для фронтенда

**✅ Безопасно для фронтенда:**
- Form Request возвращает ошибки валидации в том же формате, что и текущая реализация
- Формат ответа: `{ "message": "...", "errors": {...} }` с кодом 422
- Фронтенд использует `getApiErrorMessageMixin.js`, который корректно обрабатывает этот формат
- Laravel автоматически выбрасывает `ValidationException`, который возвращает тот же формат

**⚠️ Важно:**
- Нужно сохранить те же правила валидации
- Нужно сохранить те же сообщения об ошибках (или настроить кастомные)
- Кастомная логика валидации должна быть перенесена в кастомные правила или методы Form Request

---

## Список контроллеров с оценкой рисков

### 🔴 Высокий риск / Высокий приоритет

#### 1. **ClientController** 
- **Риск:** Высокий
- **Причина:** Дублирование правил в store/update, кастомные проверки (checkEmployeeIdDuplicate, checkPhoneDuplicates)
- **Особенности:** 
  - Сложная валидация с проверкой дубликатов
  - Много полей с условной валидацией
- **Рекомендация:** Создать `StoreClientRequest` и `UpdateClientRequest`, вынести проверки дубликатов в кастомные правила

#### 2. **OrderController**
- **Риск:** Высокий
- **Причина:** Очень сложная валидация с products и temp_products, множественные вложенные массивы
- **Особенности:**
  - Валидация массивов продуктов с разными правилами
  - Условная валидация для разных типов продуктов
- **Рекомендация:** Создать `StoreOrderRequest` и `UpdateOrderRequest`, использовать кастомные правила для сложной логики

#### 3. **UsersController**
- **Риск:** Высокий
- **Причина:** Сложная валидация с try-catch, кастомная обработка ошибок, условная валидация
- **Особенности:**
  - Множественные методы с валидацией (store, update, updateProfile, changePassword)
  - Кастомная обработка ValidationException
  - Условная валидация полей
- **Рекомендация:** Создать отдельные Request классы для каждого метода

---

### 🟡 Средний риск

#### 4. **RolesController**
- **Риск:** Средний
- **Причина:** Кастомная логика с trim и проверками перед валидацией
- **Особенности:**
  - Проверка пустого имени после trim
  - Динамическое построение unique правила с учетом company_id
- **Рекомендация:** Перенести trim в метод `prepareForValidation()`, использовать кастомное правило для unique

#### 5. **ProjectsController**
- **Риск:** Средний
- **Причина:** Динамическая валидация через метод `getValidationRules()`
- **Особенности:**
  - Условная валидация budget/currency_id/exchange_rate
  - Метод `getValidationRules()` используется в store и update
- **Рекомендация:** Использовать условные правила в Form Request через `sometimes()` или метод `rules()`

#### 6. **CurrencyHistoryController**
- **Риск:** Средний
- **Причина:** Условная валидация в зависимости от типа операции
- **Рекомендация:** Использовать условные правила в Form Request

#### 7. **ProjectContractsController**
- **Риск:** Средний
- **Причина:** Условная валидация
- **Рекомендация:** Использовать условные правила в Form Request

#### 8. **CompaniesController**
- **Риск:** Средний
- **Причина:** Сложная кастомная логика с filter_var, условная обработка полей
- **Особенности:**
  - Много кастомной логики перед и после валидации
  - Обработка boolean значений
  - Условная очистка полей при отключении rounding
- **Рекомендация:** Перенести подготовку данных в `prepareForValidation()`, использовать кастомные правила

---

### 🟢 Низкий риск

#### 9. **AuthController**
- **Риск:** Низкий
- **Причина:** Уже есть LoginRequest, нужно проверить остальные методы
- **Рекомендация:** Завершить миграцию остальных методов

#### 10. **ProductController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreProductRequest` и `UpdateProductRequest`

#### 11. **InvoiceController**
- **Риск:** Низкий
- **Причина:** Простая валидация
- **Рекомендация:** Создать `StoreInvoiceRequest` и `UpdateInvoiceRequest`

#### 12. **TransactionsController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreTransactionRequest` и `UpdateTransactionRequest`

#### 13. **CommentController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreCommentRequest` и `UpdateCommentRequest`

#### 14. **WarehouseController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreWarehouseRequest` и `UpdateWarehouseRequest`

#### 15. **WarehouseReceiptController**
- **Риск:** Низкий
- **Причина:** Простая валидация
- **Рекомендация:** Создать `StoreWarehouseReceiptRequest` и `UpdateWarehouseReceiptRequest`

#### 16. **WarehouseMovementController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreWarehouseMovementRequest` и `UpdateWarehouseMovementRequest`

#### 17. **WarehouseWriteoffController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreWarehouseWriteoffRequest` и `UpdateWarehouseWriteoffRequest`

#### 18. **CashRegistersController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreCashRegisterRequest` и `UpdateCashRegisterRequest`

#### 19. **SaleController**
- **Риск:** Низкий
- **Причина:** Простая валидация
- **Рекомендация:** Создать `StoreSaleRequest`

#### 20. **TransfersController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreTransferRequest` и `UpdateTransferRequest`

#### 21. **CategoriesController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreCategoryRequest` и `UpdateCategoryRequest`

#### 22. **TransactionCategoryController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreTransactionCategoryRequest` и `UpdateTransactionCategoryRequest`

#### 23. **OrderStatusController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreOrderStatusRequest` и `UpdateOrderStatusRequest`

#### 24. **OrderStatusCategoryController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreOrderStatusCategoryRequest` и `UpdateOrderStatusCategoryRequest`

#### 25. **ProjectStatusController**
- **Риск:** Низкий
- **Причина:** Стандартная валидация
- **Рекомендация:** Создать `StoreProjectStatusRequest` и `UpdateProjectStatusRequest`

#### 26. **OrderTransactionController**
- **Риск:** Низкий
- **Причина:** Простая валидация
- **Рекомендация:** Создать `StoreOrderTransactionRequest`

#### 27. **CompanyRoundingRulesController**
- **Риск:** Низкий
- **Причина:** Уже использует Validator::make, легко мигрировать
- **Рекомендация:** Создать `UpsertCompanyRoundingRuleRequest`

---

### ⚪ Без валидации / Требует проверки

#### 28. **UserCompanyController**
- **Риск:** Нет валидации
- **Причина:** Нет методов с валидацией
- **Рекомендация:** Проверить, нужна ли валидация для setCurrentCompany

#### 29. **AppController, CacheController, DashboardController**
- **Риск:** Требует проверки
- **Рекомендация:** Проверить наличие валидации

---

## План миграции (рекомендуемый порядок)

### Этап 1: Низкий риск (быстрая миграция)
1. CompanyRoundingRulesController
2. ProductController
3. InvoiceController
4. TransactionsController
5. CommentController
6. WarehouseController
7. WarehouseReceiptController
8. WarehouseMovementController
9. WarehouseWriteoffController
10. CashRegistersController
11. SaleController
12. TransfersController
13. CategoriesController
14. TransactionCategoryController
15. OrderStatusController
16. OrderStatusCategoryController
17. ProjectStatusController
18. OrderTransactionController

### Этап 2: Средний риск (требует внимания)
1. RolesController
2. ProjectsController
3. CurrencyHistoryController
4. ProjectContractsController
5. CompaniesController

### Этап 3: Высокий риск (требует тщательной проработки)
1. ClientController
2. OrderController
3. UsersController

---

## Рекомендации по реализации

### Для контроллеров с кастомной логикой:

1. **Использовать `prepareForValidation()`** для предобработки данных:
```php
protected function prepareForValidation()
{
    if ($this->has('name')) {
        $this->merge(['name' => trim($this->name)]);
    }
}
```

2. **Использовать кастомные правила валидации** для сложной логики:
```php
use Illuminate\Validation\Rules\Unique;

$uniqueRule = Rule::unique('roles', 'name')
    ->where('guard_name', 'api')
    ->where('company_id', $this->getCurrentCompanyId());
```

3. **Использовать условные правила** через `sometimes()`:
```php
public function rules()
{
    $rules = [
        'name' => 'required|string',
    ];
    
    if ($this->has('budget')) {
        $rules['budget'] = 'required|numeric';
        $rules['currency_id'] = 'nullable|exists:currencies,id';
    }
    
    return $rules;
}
```

4. **Сохранить формат ответа** - Laravel автоматически вернет правильный формат, но можно кастомизировать через `messages()` и `attributes()`

---

## Проверка после миграции

1. ✅ Проверить, что формат ошибок валидации остался прежним
2. ✅ Проверить, что все правила валидации работают корректно
3. ✅ Проверить кастомные проверки (дубликаты, бизнес-логика)
4. ✅ Протестировать на фронтенде все формы
5. ✅ Проверить сообщения об ошибках

---

## Итоговая оценка

- **Всего контроллеров с валидацией:** ~28
- **Низкий риск:** 19 контроллеров
- **Средний риск:** 5 контроллеров
- **Высокий риск:** 3 контроллера
- **Без валидации:** 1+ контроллеров

**Общий риск для фронтенда: НИЗКИЙ** ✅
- Form Request возвращает тот же формат ошибок
- Правила валидации остаются теми же
- Требуется только аккуратность при переносе кастомной логики

