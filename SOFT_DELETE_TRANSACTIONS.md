# Soft Delete для транзакций

## Описание

Реализован механизм "мягкого удаления" транзакций: вместо физического удаления из БД транзакции помечаются флагом `is_deleted = true` и перестают учитываться в балансах и расчетах, но остаются видимыми на фронте (пока не реализовано визуальное отображение).

## Изменения в коде

### Backend

1. **Миграция**: `2025_10_31_000001_add_is_deleted_to_transactions_table.php`
   - Добавлено поле `is_deleted` (boolean, default false)
   - Добавлен индекс `idx_transactions_is_deleted`

2. **Модель Transaction**:
   - Добавлено поле `is_deleted` в `$fillable`
   - Добавлен cast для `is_deleted` → boolean

3. **TransactionsRepository**:
   - Метод `deleteItem()`: вместо `$transaction->delete()` использует `DB::table('transactions')->where('id', $id)->update(['is_deleted' => true])`
   - Полный откат балансов кассы и клиента выполняется вручную перед soft delete
   - Метод `getItemsWithPagination()`: добавлен фильтр `where('transactions.is_deleted', false)`
   - Метод `getTotalByOrderId()`: добавлен фильтр `where('is_deleted', false)`

4. **Другие репозитории**:
   - `OrdersRepository`: добавлен фильтр `is_deleted = false` во всех запросах к транзакциям
   - `CahRegistersRepository`: добавлен фильтр `is_deleted = false` при вычислении балансов касс
   - `ProjectsRepository`: добавлен фильтр `is_deleted = false` при проверке связанных транзакций

### Frontend

1. **TransactionDto**: добавлен параметр `isDeleted` в конструктор (по умолчанию `false`)

2. **TransactionController**: передача `item.is_deleted || false` при создании DTO из API

3. **Везде, где создается TransactionDto**:
   - `TransactionController.js` - основной контроллер
   - `ClientPaymentsTab.vue` - платежи клиента
   - `ClientBalanceTab.vue` - баланс клиента  
   - `ProjectBalanceTab.vue` - баланс проекта
   - `TransactionCreatePage.vue` - копирование транзакции (всегда `false`)

4. **Миграция**: `2025_10_31_000002_add_show_deleted_transactions_to_companies_table.php`
   - Добавлено поле `show_deleted_transactions` (boolean, default false)

5. **Модель Company**:
   - Добавлено поле `show_deleted_transactions` в `$fillable`
   - Добавлен cast для `show_deleted_transactions` → boolean

## Поведение

### При удалении транзакции:
1. Откатывается баланс кассы (если `is_debt = false`)
2. Откатывается баланс клиента (с учетом логики для долговых и обычных транзакций)
3. Устанавливается `is_deleted = true` для транзакции
4. Инвалидируются все кэши

### При запросах:
- **Расчеты балансов**: ВСЕГДА исключают удаленные транзакции (`WHERE is_deleted = false`)
- **Списки транзакций**: 
  - Если настройка компании `show_deleted_transactions = false` (по умолчанию) - показываются только неудаленные
  - Если настройка компании `show_deleted_transactions = true` - показываются все транзакции (включая удаленные)

## Настройка компании

Добавлена настройка `show_deleted_transactions` в таблице `companies`:
- `false` (по умолчанию) - удаленные транзакции скрыты
- `true` - удаленные транзакции показываются вместе с активными

**ВАЖНО**: Удаленные транзакции **НЕ ВЛИЯЮТ** на расчет балансов касс и клиентов - они всегда исключаются из расчетов!

## TODO на фронте

1. Добавить визуальное отображение удаленных транзакций (зачеркнуты, серые, disabled)
2. Добавить UI для настройки `show_deleted_transactions` в настройках компании
3. Добавить возможность восстановить транзакцию (убрать is_deleted = false)

## Запуск миграции

```bash
php artisan migrate
```

