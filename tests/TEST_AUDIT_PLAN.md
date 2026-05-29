# Аудит тестов и план усиления

## Текущее состояние

- Сильные наборы: `InventoryControllerTest`, `WarehousePurchaseControllerTest`, `UsersControllerTest`, `CompanyContextResolutionTest`, `ReferencePayloadBudgetTest`, `ReferenceResourcePayloadKeysTest`.
- Слабые зоны: контроллерные CRUD smoke-тесты с проверкой только статуса/сообщения.
- Хрупкие зоны: зависимость от `Schema::hasTable` + `markTestSkipped`, seed/static id сценарии, размытые ассерты вида `assertNotEquals(200, ...)`.

## Уже примененные ограничения

- Тесты используют подключение к БД только из `.env`.
- Базовый `tests/TestCase.php` использует `DatabaseTransactions` для изоляции всех Laravel-тестов.
- В тестах запрещены `markTestSkipped()`, `RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`, `migrate:fresh`, `migrateFreshUsing`, `Schema::drop`.
- В `phpunit.xml` удален `APP_ENV=testing`.

## Приоритет P0

- Изоляция компаний в CRUD/read:
  - `test_index_returns_only_current_company_records`
  - `test_user_cannot_view_resource_from_other_company`
  - `test_user_cannot_update_resource_from_other_company`
- Полные permission-тесты по контроллерам:
  - `CategoriesControllerTest`
  - `CashRegistersControllerTest`
  - `TransactionCategoryControllerTest`
  - `RolesControllerTest`
  - `ProjectStatusControllerTest`
  - `OrderStatusControllerTest`
  - `OrderStatusCategoryControllerTest`
  - `WarehouseMovementControllerTest`
  - `TransfersControllerTest`
  - `InvoiceControllerTest`
- Финансовые инварианты:
  - `OrderControllerTest`: создание/удаление и влияние на долги/транзакции
  - `TransactionsControllerTest`: коррекция/удаление и восстановление балансов
  - `SaleControllerTest`: отрицательные остатки и корректное списание

## Приоритет P1

- Идемпотентность endpoint-ов с side-effects.
- Негативные проверки валидации для вложенных `products/items`.
- Усиление smoke-тестов до DB-assert уровня (`assertDatabaseHas/Missing` + `assertJsonPath`).

## Приоритет P2

- Рационализация legacy web auth тестов (`tests/Feature/Auth/*`) относительно реального API потока.
- Устранение magic id и прямых вставок `DB::table()->insert(...)` в пользу фабрик/явных setup helper-ов.

## Быстрые победы

- Удалить `Schema::hasTable + markTestSkipped` из всех `Feature` тестов.
- Заменить размытые ассерты на точные `403/404/422`.
- Добавить минимальные проверки побочных эффектов после create/update/delete в справочниках.
