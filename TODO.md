# TODO

## Справочники с поддержкой системных и пользовательских записей

Сделать возможность создавать записи для каждой компании в следующих справочниках (сейчас они глобальные):

1. **TransactionCategoryRepository** — глобальный справочник
   - Нужно добавить поддержку `company_id` (nullable)
   - Системные записи (company_id = null) — общие для всех компаний
   - Пользовательские записи (company_id != null) — свои для каждой компании

2. **ProjectStatusRepository** — глобальный справочник
   - Нужно добавить поддержку `company_id` (nullable)
   - Системные записи (company_id = null) — общие для всех компаний
   - Пользовательские записи (company_id != null) — свои для каждой компании

3. **OrderStatusRepository** — глобальный справочник
   - Нужно добавить поддержку `company_id` (nullable)
   - Системные записи (company_id = null) — общие для всех компаний
   - Пользовательские записи (company_id != null) — свои для каждой компании

### Что нужно сделать:
- [ ] Добавить миграцию для добавления поля `company_id` (nullable) в таблицы:
  - `transaction_categories`
  - `project_statuses`
  - `order_statuses`
- [ ] Обновить модели (TransactionCategory, ProjectStatus, OrderStatus) для поддержки `company_id`
- [ ] Обновить репозитории для фильтрации по `company_id`:
  - При получении данных показывать системные + записи текущей компании
  - При создании устанавливать `company_id` текущей компании
  - Системные записи (company_id = null) должны быть защищены от редактирования/удаления
- [ ] Обновить контроллеры для работы с `company_id`
- [ ] Обновить кеширование с учетом `company_id`

## Переход на Laravel Sanctum

- [ ] Установить Sanctum, опубликовать конфиг и миграции, запустить `php artisan migrate`
- [ ] В `config/auth.php` и `config/sanctum.php` настроить guard, stateful домены, TTL
- [ ] Удалить зависимости и конфиги `tymon/jwt-auth`, обновить middleware `auth:sanctum`
- [ ] Переписать `AuthController@login/me/logout/refresh` на выдачу Sanctum токенов и отзыв
- [ ] Для cookie-flow настроить CORS (`supports_credentials`), `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`
- [ ] Для PAT-flow реализовать сохранение токенов, логаут (удаление токена), опциональный refresh-эндпоинт
- [ ] Обновить фронтенд API-клиент: получение токена/куки, заголовки `Authorization`, обработка 401
- [ ] Обновить документацию/Swagger и добавить задачи по ревоку и клинапу старых JWT

