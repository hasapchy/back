## Статус: клиенты → ресурсы API + фронт

### Ресурсы
- `ClientResource` — детальный клиент; баланс из `client_balances` (видимый дефолтный).
- `ClientSearchResource` — поиск/селект; тот же источник баланса.
- `ClientBalanceResource` — строка баланса клиента (`ClientBalancePayload`).

### Эндпоинты
- `GET /clients` — `{ items, meta }` внутри `data` (`type_counts`, `suppliers_count` в meta).
- `GET /clients/all`, `GET /clients/search` — требуют `viewAny` на `Client`; mutual-settlements типы через `MutualSettlementsAccess`.
- `GET /clients/search` — массив `ClientSearchResource` без обёртки `items`.
- `show`, `store`, `update`, `all` — `ClientResource`.

### Поиск (`ClientSearchResource`)
- Поля: `id`, `client_type`, `balance`, `is_supplier`, `is_conflict`, `first_name`, `last_name`, `position`, `primary_phone`.
- Нет: `patronymic`, `phones[]`, `employee`, `status` (только активные).

### Баланс
- Колонка `clients.balance` удалена; единый источник — `client_balances`.
- Удаление клиента блокируется при ненулевом `client_balances.balance`.

### Фронт
- `ClientController.js`: `updateItem` → `{ item, message }`; `searchItems(term, typeFilter, signal)`.
- `ClientCreatePage`: тип `employee` недоступен при создании.
- Удалены: `ClientOperationsTab.vue`, `SimpleClientSearch.vue`.
