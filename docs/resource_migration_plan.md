### Текущее состояние
- В `app/Http/Controllers/Api` 32 контроллера; ответы собираются вручную через `response()->json` и хелперы `successResponse`, `paginatedResponse` в `BaseController`.
- Директория `app/Http/Resources` пуста, ресурсные классы не используются.
- Репозитории возвращают модели/пагинаторы, контроллеры форматируют JSON сами (часто поля `items`, `current_page`, `next_page`, `last_page`, `total`).
- Фронт использует контроллеры в `front/src/api` и DTO (`front/src/dto/**`) c ожиданием существующих полей (`items`, `current_page`, `unpaid_orders_total` и т.д.).

### Цель перехода
- Вынести формирование ответов в Laravel Resources/ResourceCollections.
- Унифицировать метаданные пагинации и дополнительные поля, чтобы фронт получал стабильную схему.
- Сократить дублирование форматирования в контроллерах и репозиториях.

### План миграции
1) **Базовая инфраструктура**
   - Создать `App\Http\Resources\BaseJsonResource` для общих трейт-методов (напр. `withPaginatedMeta`).
   - Определить единый формат пагинации: `data` для коллекции, `meta` для `current_page`, `next_page`, `last_page`, `total`, а специальные поля (например, `unpaid_orders_total`) включать через `additional`.
   - Обновить `BaseController::paginatedResponse`/`successResponse`, чтобы они принимали ресурсы и не дублировали логику.

2) **Приоритетные ресурсы (используются чаще всего на фронте)**
   - Пользователи: `UserResource`, `UserCollection`. Включить связанные `permissions`, `roles`, `company_roles`, `clientAccounts`.
   - Заказы: `OrderResource`, `OrderCollection` c вложенными `products`, `temp_products`, `status`, `client`, `project`, `warehouse`, `cash`, мета `unpaid_orders_total`.
   - Товары/склады: `ProductResource`, `WarehouseResource`, `WarehouseStockResource`, `WarehouseMovementResource`, `WarehouseReceiptResource`, `WarehouseWriteoffResource`.
   - Клиенты и проекты: `ClientResource`, `ProjectResource`, `ProjectStatusResource`, `ProjectContractResource`.
   - Финансы: `TransactionResource`, `TransactionCategoryResource`, `CashRegisterResource`, `TransferResource`, `SaleResource`.

3) **Оставшиеся контроллеры**
   - Категории/статусы: `CategoryResource`, `OrderStatusResource`, `OrderStatusCategoryResource`, `CompanyRoundingRuleResource`.
   - Прочие: `CommentResource`, `CurrencyHistoryResource`, `RoleResource`, `PermissionResource`, `CacheResource` (если понадобится однородность ответов).

4) **Изменение контроллеров**
   - Для `index`/списков возвращать `*Collection::make($paginator)->additional(meta)` вместо ручных массивов.
   - Для `show`/`store`/`update` возвращать `new *Resource($model)` или `Resource::make(...)` (c `->additional` при необходимости статусов/сообщений).
   - Для операций без данных (delete) вернуть стандартный `{ "message": ... }` либо пустой `204` в одном стиле.
   - Удалить из контроллеров дублируемое форматирование JSON, оставить только бизнес-логику/валидацию/доступ.

5) **Согласование с фронтом**
   - Сохранить поля, которые потребляет текущий DTO: либо повторить схему (`items`, `current_page`, …) через `additional`, либо адаптировать DTO к ресурсу (`data`, `meta`). Рекомендация: в коллекциях вернуть `data` + `meta`, а во фронт-контроллерах/DTO добавить поддержку `meta` и `data`, сохранив обратную совместимость на переходный период.
   - Для уникальных полей (например, `unpaid_orders_total` в заказах) добавить их в `meta` и поддержать во фронтовых DTO.

6) **Тестирование и совместимость**
   - Для каждого контроллера добавить feature-тесты, проверяющие структуру `data` и `meta`.
   - Пройтись по кеш-инвалидациям (`CacheService::invalidate*`) и убедиться, что они не завязаны на старый формат ответа.
   - Обновить swagger/документацию (если используется l5-swagger) под новый формат.

7) **Поэтапный rollout**
   - Этап 1: Users, Orders, Products, Warehouses (ключевые страницы). Обновить фронтовые DTO/контроллеры для поддержки `data/meta`.
   - Этап 2: Clients, Projects, Transactions, CashRegisters, Transfers, Sales.
   - Этап 3: Остальные (категории, статусы, комментарии, справочники).
   - На каждом этапе: покрыть тестами, выпустить миграцию фронта, затем переходить к следующему.

### Минимальные правила формата
- Коллекции: `return OrderResource::collection($paginator)->additional(['meta' => [...]]);`
- Одиночные сущности: `return OrderResource::make($order)->additional(['message' => '...']);`
- Ошибки продолжить отдавать через `BaseController::errorResponse*`, чтобы фронт не менялся.


