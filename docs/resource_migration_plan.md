### Назначение документа
Описание текущей конвенции формирования ответов API через Laravel Resources, перечень оставшихся задач по унификации и принципы расширения.

### Текущее состояние

#### Контроллеры
- В `app/Http/Controllers/Api` 51 контроллер, наследует `BaseController` (`AuthorizesRequests`, `ValidatesRequests`).
- Базовые хелперы ответов: `BaseController::successResponse`, `errorResponse`, `validationErrorResponse`, `paginatedResponse`. Все возвращают `JsonResponse` через приватный `messageResponse`.
- Фактический формат пагинации, который сложился в проекте (пример из `OrderController::list`):
  ```php
  return $this->successResponse([
      'items' => OrderResource::collection($items->items())->resolve(),
      'meta'  => [
          'current_page' => $items->currentPage(),
          'next_page'    => $items->nextPageUrl(),
          'last_page'    => $items->lastPage(),
          'per_page'     => $items->perPage(),
          'total'        => $items->total(),
          // доменные расширения (опционально):
          // 'unpaid_orders_total' => ..., 'status_counts' => [...]
      ],
  ]);
  ```
- Для одиночных сущностей используется `(new XResource($model))->additional(['message' => ...])->response()` либо `XResource::make($model)`.
- Для операций без данных — `successResponse(null, $message)` или `errorResponse(...)`.

#### Ресурсы (`app/Http/Resources`)
- `BaseDomainResource` — базовый класс с нормализацией поля `creator` (унифицирует `creator_id`/`creator_name`/`creator_photo`/связь `user`).
- 49 ресурсов покрывают все основные домены:
  - **Пользователи/доступ**: `UserResource`, `RoleResource`.
  - **Заказы**: `OrderResource` (с предзагрузкой связей через `eagerLoadRelationsForOrderDetail*`), `OrderStatusResource`, `OrderStatusCategoryResource`.
  - **Клиенты/проекты**: `ClientResource`, `ClientSearchResource`, `ClientBalanceResource`, `ProjectResource`, `ProjectStatusResource`, `ProjectContractResource`.
  - **Склад**: `WarehouseResource`, `WarehouseStockResource`, `WarehouseMovementResource`, `WarehouseReceiptResource`, `WarehouseReceiptProductResource`, `WarehouseWriteoffResource`, `WhWaybillResource`, `InventoryResource`, `InventoryItemResource`.
  - **Финансы**: `TransactionResource`, `TransactionCategoryResource`, `TransactionTemplateResource`, `RecScheduleResource`, `CashRegisterResource`, `TransferResource`, `SaleResource`, `SaleProductResource`, `InvoiceResource`, `InvoiceNestedOrderResource`, `CurrencyResource`, `CurrencyHistoryResource`.
  - **Задачи/HR**: `TaskResource`, `TaskStatusResource`, `LeaveResource`, `LeaveTypeResource`, `DepartmentResource`, `CompanyResource`, `CompanyHolidayResource`.
  - **Контент/инфра**: `CategoryResource`, `ProductResource`, `CommentResource`, `MessageTemplateResource`, `NewsResource`, `AppNotificationResource`.
  - **Чат** (`Resources/Chat`): `ChatResource`, `ChatListItemResource`, `ChatMessageResource`.

#### Фронт
- `front/src/api/BaseController.js#getItems` ожидает `response.data.data.items` + `response.data.data.meta.{current_page,next_page,last_page,per_page,total}` — это тот формат, что отдают контроллеры.
- `front/src/api/BaseController.js#getItem` читает `response.data.data` для одиночных сущностей.
- `front/src/dto/app/PaginatedResponseDto` хранит `items`, `currentPage`, `nextPage`, `lastPage`, `total` (+ опциональные расширения вроде `unpaidOrdersTotal`).

### Соглашения по формату ответа

| Сценарий | Возвращать | Пример |
|---|---|---|
| Список с пагинацией | `successResponse(['items' => Resource::collection($p->items())->resolve(), 'meta' => $meta])` | `OrderController::list` |
| Полный список (без пагинации) | `successResponse(Resource::collection($items)->resolve())` | `*Controller::all` |
| Одиночная сущность (`show`) | `(new Resource($model))->response()` | `OrderController::show` |
| Создание (`store`) | `(new Resource($model))->additional(['message' => '...'])->response()->setStatusCode(201)` | `OrderController::store` |
| Обновление (`update`) | `(new Resource($model))->additional(['message' => '...'])->response()` | `OrderController::update` |
| Удаление (`destroy`) | `successResponse(null, $message)` | стандарт |
| Ошибка валидации | `validationErrorResponse($validator)` (`422`, `errors`) | стандарт |
| Бизнес-ошибка | `errorResponse($message, $status)` | стандарт |

`meta` в коллекциях обязан содержать ключи `current_page`, `next_page`, `last_page`, `per_page`, `total`. Доменные расширения (`unpaid_orders_total`, `status_counts` и т.п.) добавляются туда же без отдельного контейнера.

### Принципы написания ресурсов
1. Наследовать `BaseDomainResource`, если в выдаче присутствует поле `creator`/`creator_*` — это даст однородный объект `creator: { id, name, photo }`.
2. Для тяжёлых сущностей объявлять статические методы `eagerLoadRelationsFor*` в самом ресурсе (см. `OrderResource::eagerLoadRelationsForOrderDetail`) и подключать их в репозитории.
3. Никаких `parent::toArray()`-фолбэков на сырую модель в продуктовых ресурсах: явно перечислять поля.
4. Связи отдавать только при наличии `relationLoaded(...)`, чтобы не вызывать N+1 в hot-path.
5. Числовые денежные поля приводить к `float` в самом ресурсе; форматирование — на стороне фронта.
6. Даты сериализовать в ISO-8601 (`Carbon::toIso8601String()`), либо передавать строку как есть.

### Оставшиеся задачи

1. **Унификация `paginatedResponse`.**
   Метод `BaseController::paginatedResponse` использует другой формат (`{ data: [...], meta: {...} }`) и фактически дублирует логику. Варианты:
   - Удалить метод, оставив единый шаблон через `successResponse(['items' => ..., 'meta' => ...])`.
   - Либо переписать его так, чтобы он принимал ресурс и возвращал `{ data: { items, meta } }`, и постепенно перевести индексные методы на него.

2. **Сокращение бойлерплейта в `index`-методах.**
   Сейчас meta собирается вручную в каждом контроллере. После решения по п.1 — вынести сборку meta в общий хелпер (например, `BaseController::collectionResponse(JsonResource $collection, LengthAwarePaginator $p, array $extraMeta = [])`).

3. **Тесты структуры ответа.**
   Добавить feature-тесты, проверяющие наличие ключей `data.items`, `data.meta.current_page` и т.п. для всех публичных индексных эндпоинтов. Сейчас покрытие фрагментарное.

4. **Документация Scribe.**
   Прогнать `php artisan scribe:generate` после любых изменений ресурсов/контроллеров; убедиться, что примеры ответов соответствуют фактическому формату.

5. **Точечные ресурсы при необходимости.**
   `PermissionResource` и `CacheResource` из старого плана не созданы — фронт их не запрашивает в виде ресурсов. Создавать только если появится явный потребитель.

### Чеклист при добавлении нового эндпоинта
- [ ] Контроллер наследует `BaseController` и не форматирует JSON вручную.
- [ ] Создан/переиспользован соответствующий `*Resource` в `app/Http/Resources/`.
- [ ] Ответ собран по таблице соглашений выше.
- [ ] В репозитории/сервисе добавлен eager-loading для всех полей, которые требует ресурс.
- [ ] Прогнан `scribe:generate`, проверен пример ответа.
- [ ] Добавлен/обновлён feature-тест на структуру ответа.
