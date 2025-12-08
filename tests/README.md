# Инструкция по запуску тестов

## Проблема

Тесты используют базу данных, в которой могут отсутствовать таблицы. Ошибка:
```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'rem_online.companies' doesn't exist
```

## Решения

### Вариант 1: Выполнить миграции в тестовой БД (Рекомендуется)

1. Убедитесь, что у вас есть тестовая база данных (или используйте существующую)
2. Выполните миграции:
```bash
php artisan migrate
```

3. Запустите тесты:
```bash
php artisan test
```

### Вариант 2: Настроить отдельную тестовую БД

1. Создайте файл `.env.testing`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rem_online_test
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

2. Создайте тестовую базу данных:
```sql
CREATE DATABASE rem_online_test;
```

3. Выполните миграции в тестовой БД:
```bash
php artisan migrate --env=testing
```

4. Запустите тесты:
```bash
php artisan test
```

### Вариант 3: Использовать SQLite in-memory (Быстрее всего)

1. Обновите `phpunit.xml`:
```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <!-- остальные настройки -->
</php>
```

2. Запустите тесты:
```bash
php artisan test
```

**Примечание:** SQLite может иметь ограничения по сравнению с MySQL, но для большинства тестов этого достаточно.

## Текущая настройка

Сейчас тесты используют `DatabaseTransactions` вместо `RefreshDatabase`, что означает:
- Тесты используют существующую базу данных
- Все изменения откатываются после каждого теста
- Таблицы должны существовать до запуска тестов

## Проверка перед запуском

Убедитесь, что все необходимые таблицы существуют:
```bash
php artisan migrate:status
```

Если есть непроведенные миграции, выполните:
```bash
php artisan migrate
```

