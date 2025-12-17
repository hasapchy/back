## Статус: клиенты → ресурсы/коллекции (API + фронт)

### Что сделано
- Добавлены ресурсы для клиентов:
  - `App\Http\Resources\ClientResource` — детальный клиент.
  - `App\Http\Resources\ClientCollection` — пагинация с `data` + `meta {current_page,last_page,per_page,total,next_page,prev_page}`.
  - `App\Http\Resources\ClientSearchResource` — облегчённый ответ для поиска.
- Контроллер `ClientController` переведён на ресурсы:
  - `index` → `ClientCollection`.
  - `show`, `store`, `update`, `all` → `ClientResource`.
- Поиск клиентов:
  - Ответ: массив `ClientSearchResource` без обёртки (`data` не используется здесь).
  - Поля поиска: `id, client_type, balance, is_supplier, is_conflict, first_name, last_name, contact_person, position, primary_phone`.
  - Убраны: `patronymic`, `phones` массив, `employee`, `employee_id`, `status` (всегда активные).
  - Поиск по: имени, фамилии, контактному лицу, должности, телефону, email. `patronymic` из поиска убран.

### Фронт обновлён под ресурсы
- API: `front/src/api/ClientController.js`
  - `getItem`, `getItems`, `getListItems`, `storeItem`, `updateItem` работают с форматом `data`/`meta`.
  - `searchItems` — массив без обёртки.
- DTO:
  - `ClientDto`: убран `client_id` из телефонов/email (ресурс их не отдаёт).
  - `ClientSearchDto`: поля совпадают с `ClientSearchResource`; нет `patronymic`, `phones`, `employee`, `employee_id`; добавлен `primaryPhone`; `status` задаётся `true`.
- Компонент поиска `ClientSearch.vue`:
  - Отображает телефон из `primaryPhone`.
  - Показывает баланс в списках (поиск и последние клиенты).
  - Последние клиенты подгружаются из store; dropdown по ширине инпута.
- Store:
  - `loadClients` читает `/clients/all` в формате ресурсов (`data`).

### Как было
- Контроллеры формировали JSON вручную (`items`, `current_page` и т.п.), поиск отдавал полные модели с лишними полями (`patronymic`, массив `phones`, `status`, `employee`), фронт парсил старую структуру.
- Фронт контроллеры/DTO ожидали старые поля (`items`, `current_page`, массив `phones` для поиска).

### Что важно помнить
- Пагинация теперь `data` + `meta` (коллекции).
- Поиск отдаёт простой массив без обёртки `data`.
- Неактивные клиенты в поиске не возвращаются (фильтр `status=true`), поэтому поле `status` из поиска убрано.

### Следующие шаги для других модулей
- Повторить паттерн: ресурс для детали, коллекция для пагинации, облегчённый ресурс для поиска/селектов.
- Обновить фронтовые DTO/контроллеры под `data/meta` и облегчённые ответы без лишних полей.

