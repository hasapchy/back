# Инструкция по запуску тестов

## Текущая политика тестовой инфраструктуры

- Тесты всегда работают с текущим подключением БД из `.env`.
- `APP_ENV=testing` не используется.
- Изоляция выполняется через `DatabaseTransactions` в базовом `tests/TestCase.php`.
- `RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`, `migrate:fresh` и `Schema::drop` запрещены в тестах.
- Перед запуском тестов таблицы должны уже существовать.

## Запуск

```bash
cd back
php artisan test
```

## Проверка схемы перед запуском

```bash
cd back
php artisan migrate:status
```

Если есть непроведенные миграции:

```bash
cd back
php artisan migrate
```

## План reference payload — завершён

Статус в конфиге: `back/config/reference_contracts.php` → **`plan_status.slim_reference_payload_complete`**, версия контрактов и кэша фронта: **`cache_version` `1.7`** в том же файле и **`cacheVersion`** в `front/src/store/config.js`.

Сводка: списки и **`/all`** для справочников и сущностей выше отдают **`Reference*Resource`** (через **`BaseController::wave1IndexCollection`** / **`wave1SingleResource`** и аналогично для **`/projects`**, **`/projects/all`**, **`/tasks`**), тяжёлые поля в списках урезаны; **`show`**, создание и обновление — по правилам сущности (часто полный **`Resource`**). Урезанные **`/search`**: products, users, clients. Включение slim-ответов: **`config/features.php`** → **`reference_wave1`**, **`reference_wave1_index_show`**; canary — **`reference_contracts.canary`** (по умолчанию выключен, см. **`ReferenceWave1CanaryTest`**). Телеметрия размера **`data`**: **`REFERENCE_TELEMETRY`** / **`features.reference_telemetry`**. Тесты ключей — **`ReferenceResourcePayloadKeysTest`**, бюджеты — **`ReferencePayloadBudgetTest`** (в т.ч. **`projects_index`**, **`projects_all`**, **`tasks_index`** и др.). Бенчмарк: **`php artisan reference:benchmark-payload`** (**`ReferencePayloadBenchmarkTest`**); сущности перечислены в **`--help`**; группа **`projects_tasks`** (и алиас **`all_wave5`**) — прогон **`projects`** + **`tasks`**.

Запуск только тестов контрактов и бюджетов:

```bash
cd back
php artisan test tests/Unit/ReferenceResourcePayloadKeysTest.php tests/Unit/ReferenceWave1CanaryTest.php tests/Unit/ReferencePayloadBenchmarkTest.php tests/Feature/ReferencePayloadBudgetTest.php tests/Feature/WarehouseControllerTest.php
```

В PHPUnit опция **`--filter`** задаётся **один раз**; при повторном указании остаётся только последнее значение (остальные игнорируются). Пример выборочной проверки контрактов, бюджетов и smoke бенчмарка:

```bash
cd back
php artisan test tests/Unit/ReferenceResourcePayloadKeysTest.php tests/Unit/ReferencePayloadBenchmarkTest.php tests/Feature/ReferencePayloadBudgetTest.php --filter="test_transaction_template_reference_keys|test_leave_reference_keys|test_project_reference_keys|test_task_reference_keys|test_wave3_reference_list_endpoints_respect_payload_budgets|test_wave4_projects_all_respects_payload_budget|test_projects_index_respects_payload_budget|test_wave5_tasks_index_respects_payload_budget|test_projects_row_shows_payload_savings_vs_full_description|test_tasks_row_shows_payload_savings_vs_full_description_and_attachments|test_warehouse_row_includes_savings_fields|test_message_templates_row_shows_payload_savings_vs_full_content"
```

`ReferencePayloadBudgetTest` также содержит **`test_wave2_reference_list_endpoints_respect_payload_budgets`**, **`test_wave3_reference_list_endpoints_respect_payload_budgets`**, **`test_wave4_projects_all_respects_payload_budget`**, **`test_projects_index_respects_payload_budget`**, **`test_wave5_tasks_index_respects_payload_budget`**. При необходимости добавьте другие feature‑тесты проекта тем же вызовом.

### Сравнение экономии (пример бенчмарка, локальная машина)

Примеры: `reference:benchmark-payload --entity=warehouses`, `--entity=cash_registers`, `--entity=departments`, `--entity=message_templates`, `--entity=company_holidays`, `--entity=transaction_templates`, `--entity=leaves`, `--entity=projects`, `--entity=tasks`, `--entity=all_wave2`, `--entity=all_wave3`, `--entity=projects_tasks` (алиас **`all_wave5`**: projects+tasks) (по умолчанию **`--counts=1000,5000,10000`**). Данные синтетические; **`save_time_s`** может колебаться и быть отрицательным на отдельном замере.

**Склады (`warehouses`)** — два пользователя на каждый склад, полный ответ через `Model::toArray()`.

| count | full_bytes | ref_bytes | ratio   | save_bytes_% | full_ms | ref_ms | save_time_s |
|------:|-----------:|----------:|--------:|-------------:|--------:|-------:|------------:|
|  1000 |    597_903 |   450_903 | 0.75414 |        24.59 | 135.631 | 57.203 |       0.078 |
|  5000 |  2_993_903 | 2_258_903 | 0.75450 |        24.55 | 678.472 | 286.12 |       0.392 |
| 10000 |  5_988_904 | 4_518_904 | 0.75455 |        24.55 | 1378.621 | 579.003 |       0.800 |

**Кассы (`cash_registers`)** — одна валюта, один пользователь на кассу.

| count | full_bytes | ref_bytes | ratio   | save_bytes_% | full_ms  | ref_ms  | save_time_s |
|------:|-----------:|----------:|--------:|-------------:|---------:|--------:|------------:|
|  1000 |    617_903 |   416_903 | 0.67471 |        32.53 |  150.473 | 114.202 |       0.036 |
|  5000 |  3_093_903 | 2_088_903 | 0.67517 |        32.48 |  761.172 | 807.967 |      -0.047 |
| 10000 |  6_188_904 | 4_178_904 | 0.67523 |        32.48 | 1365.944 | 1064.137 |       0.302 |

**Группа `all_wave2` (`php artisan reference:benchmark-payload --entity=all_wave2`)** — один прогон на машине разработчика (Windows 10, `d:\OSPanel\domains\birhasap\back`). Для **`company_holidays`** в этом синтетическом сценарии **`ref_bytes` > `full_bytes`** (ratio > 1): полный **`CompanyHolidayResource`** здесь тянет компактный **`toArray()`** модели, а reference задаёт те же поля явно — сравнение остаётся ориентиром по команде, а не гарантией порядка байт на проде.

**Отделы (`departments`)**

| count | full_bytes | ref_bytes | ratio   | save_bytes_% | full_ms | ref_ms | save_time_s |
|------:|-----------:|----------:|--------:|-------------:|--------:|-------:|------------:|
|  1000 |    860_796 |   703_796 | 0.81761 |        18.24 | 203.995 | 110.303 |       0.094 |
|  5000 |  4_312_796 | 3_527_796 | 0.81798 |        18.20 | 996.651 | 551.635 |       0.445 |
| 10000 |  8_627_798 | 7_057_798 | 0.81803 |        18.20 | 2269.254 | 1124.159 |       1.145 |

**Шаблоны сообщений (`message_templates`)** — в полном ответе большой **`content`**; в reference для списков **`content`** нет.

| count | full_bytes | ref_bytes | ratio   | save_bytes_% | full_ms | ref_ms | save_time_s |
|------:|-----------:|----------:|--------:|-------------:|--------:|-------:|------------:|
|  1000 |  2_961_903 |   365_903 | 0.12354 |        87.65 | 120.760 | 86.641 |       0.034 |
|  5000 | 14_813_903 | 1_833_903 | 0.12380 |        87.62 | 598.974 | 394.156 |       0.205 |
| 10000 | 29_628_904 | 3_668_904 | 0.12383 |        87.62 | 1267.053 | 790.880 |       0.476 |

**Корпоративные праздники (`company_holidays`)** — см. примечание выше про ratio > 1 на синтетике.

| count | full_bytes | ref_bytes | ratio   | save_bytes_% | full_ms | ref_ms | save_time_s |
|------:|-----------:|----------:|--------:|-------------:|--------:|-------:|------------:|
|  1000 |    128_903 |   174_903 | 1.35686 |       -35.69 | 54.716 | 93.997 |      -0.039 |
|  5000 |    648_903 |   878_903 | 1.35444 |       -35.44 | 283.640 | 453.201 |      -0.170 |
| 10000 |  1_298_904 | 1_758_904 | 1.35415 |       -35.41 | 561.978 | 908.879 |      -0.347 |

Телеметрия: **`REFERENCE_TELEMETRY=true`** — в логах метки **`benchmark.<entity>.(full|reference).<count>`**.

Сохранить отчёт: `php artisan reference:benchmark-payload --entity=all_wave2 --save=storage/app/reference-benchmark-all-wave2.json` или с **`--json`** для stdout + файл.

