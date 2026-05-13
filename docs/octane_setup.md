# Развёртывание Laravel Octane + FrankenPHP на Linux

Инструкция для Ubuntu 22.04 / 24.04 / Debian 12. Для других дистрибутивов меняются только команды установки пакетов.

> **Важно про Windows.** FrankenPHP официально **не поддерживает** запуск нативно под Windows (Octane выдаёт ошибку при попытке скачать бинарник). Поэтому Octane тестируется только на Linux/macOS либо через WSL2/Docker на Windows.

> **Если запускаете локально через WSL2.** Это валидный сценарий для разработки и первичной проверки Octane. Для локального режима можно пропустить разделы про `systemd`, `Nginx` и `HTTPS`, оставить запуск в foreground через `php artisan octane:start`, а внешний доступ выполнять через проброс порта WSL.

---

## 0. Что должно быть на сервере до начала

- Чистая Ubuntu 22.04+ или Debian 12 (root или sudo-доступ).
- Открытые порты `80`, `443`, `6001` (Reverb).
- Доменное имя, направленное на сервер (опционально, для HTTPS).
- MySQL 8 / MariaDB 10.6+ либо подключение к существующей БД.
- Redis (рекомендуется для cache/queue/session под Octane).

---

## 1. Установка системных пакетов

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y \
    software-properties-common \
    curl wget git unzip zip \
    nginx \
    redis-server \
    supervisor \
    ca-certificates \
    lsb-release apt-transport-https
```

---

## 2. Установка PHP 8.3 + нужные расширения

Laravel 10 + Octane стабильно работают на PHP 8.2/8.3. Берём 8.3 — он быстрее.

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y \
    php8.3 php8.3-cli php8.3-common php8.3-fpm \
    php8.3-mysql php8.3-redis \
    php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-bcmath php8.3-intl php8.3-zip \
    php8.3-gd php8.3-imagick \
    php8.3-opcache
```

> В пакетах Ondrej для Ubuntu 24.04 (и в Debian-сборках PHP) отдельных пакетов `php8.3-pcntl` и `php8.3-sockets` нет: `pcntl` встроен в `php8.3-cli`, расширение `sockets` входит в `php8.3-common`. Достаточно установить эти два пакета — они уже перечислены выше.

Проверка:

```bash
php -v
# PHP 8.3.x ...
php -m | grep -E 'pcntl|sockets|redis|opcache'
```

> **Важно:** `pcntl` и `sockets` обязательны для Octane.

### Настройка `php.ini` для CLI (Octane запускается через CLI)

```bash
sudo nano /etc/php/8.3/cli/php.ini
```

Найдите и измените:

```ini
memory_limit = 512M
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0     ; на проде; на staging оставьте 1
opcache.jit_buffer_size = 128M
opcache.jit = tracing
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

---

## 3. Установка Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

---

## 4. Клонирование проекта

```bash
sudo mkdir -p /var/www
sudo chown -R $USER:$USER /var/www
cd /var/www

git clone <ВАШ_РЕПО_URL> birhasap
cd birhasap/back
```

Если у вас монорепо — клонируете в `/var/www/birhasap`, и работаете в подпапке `back/`.

---

## 5. Установка зависимостей

```bash
cd /var/www/birhasap/back
composer install --no-dev --optimize-autoloader
```

> На staging можно без `--no-dev`, чтобы были доступны Telescope/Ignition для отладки.

---

## 6. Настройка `.env`

```bash
cp .env.example .env
nano .env
```

Минимально нужно поправить под Octane:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# БД
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=birhasap
DB_USERNAME=birhasap_user
DB_PASSWORD=ваш_пароль

# Redis для всего, что только можно (важно под Octane)
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Octane
OCTANE_SERVER=frankenphp
OCTANE_HTTPS=false
OCTANE_HOST=127.0.0.1
OCTANE_PORT=8000
OCTANE_WORKERS=4
OCTANE_MAX_REQUESTS=500

# Reverb
BROADCAST_DRIVER=reverb
REVERB_APP_ID=hasapchy-app
REVERB_APP_KEY=hasapchy-key
REVERB_APP_SECRET=ваш_секрет
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=6001
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

> **Почему Redis важен под Octane:** драйверы `array` и `file` под Octane либо протекают, либо не масштабируются. Redis — единственный безопасный вариант для cache/session/queue.

---

## 7. Подготовка приложения

```bash
php artisan key:generate          # если ключа ещё нет
php artisan storage:link
php artisan migrate --force

# Кэширование (важно для производительности)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Дать права на `storage/` и `bootstrap/cache/`:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

## 8. Установка Octane + FrankenPHP

В папке `back/`:

```bash
php artisan octane:install --server=frankenphp
```

Octane скачает бинарник `frankenphp` в `vendor/laravel/octane/bin/` для вашей ОС.

Появится файл `config/octane.php`. Обычно его править не нужно.

---

## 9. Тестовый запуск

```bash
php artisan octane:start \
    --server=frankenphp \
    --host=127.0.0.1 \
    --port=8000 \
    --workers=4 \
    --max-requests=500
```

В другом терминале проверьте:

```bash
curl -i http://127.0.0.1:8000/api/health
# или любой ваш публичный эндпоинт
```

Если возвращает корректный JSON — Octane работает. `Ctrl+C` для остановки.

### Прогрев и проверка утечек памяти

```bash
# Прогнать 100 запросов
ab -n 100 -c 10 http://127.0.0.1:8000/api/some-endpoint

# Параллельно в другом терминале смотреть память воркеров
watch -n 1 'ps aux | grep frankenphp | grep -v grep'
```

Память должна **колебаться, а не расти бесконечно**. Если RSS у воркера постоянно увеличивается — есть утечка, ищите по списку причин в `octane_post_install_checklist` ниже.

---

## 10. Запуск как сервис (systemd) — для постоянной работы

### 10.1. Сервис Octane

```bash
sudo nano /etc/systemd/system/octane.service
```

```ini
[Unit]
Description=Laravel Octane (FrankenPHP) for birhasap
After=network.target redis-server.service mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/birhasap/back
ExecStart=/usr/bin/php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=8000 --workers=4 --max-requests=500
ExecReload=/usr/bin/php artisan octane:reload
Restart=always
RestartSec=5
LimitNOFILE=65536

Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable octane
sudo systemctl start octane
sudo systemctl status octane
```

### 10.2. Сервис Reverb (WebSocket)

```bash
sudo nano /etc/systemd/system/reverb.service
```

```ini
[Unit]
Description=Laravel Reverb WebSocket Server
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/birhasap/back
ExecStart=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=6001
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable reverb
sudo systemctl start reverb
sudo systemctl status reverb
```

### 10.3. Сервис очередей

```bash
sudo nano /etc/systemd/system/queue.service
```

```ini
[Unit]
Description=Laravel Queue Worker
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/birhasap/back
ExecStart=/usr/bin/php artisan queue:work redis --tries=3 --timeout=120 --sleep=1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable queue
sudo systemctl start queue
```

### 10.4. Шедулер (cron)

```bash
sudo crontab -u www-data -e
```

Добавить:

```cron
* * * * * cd /var/www/birhasap/back && php artisan schedule:run >> /dev/null 2>&1
```

---

## 11. Nginx как reverse-proxy с HTTPS

Octane работает на `127.0.0.1:8000`. Снаружи стоит Nginx, который:
- Терминирует HTTPS.
- Раздаёт статику напрямую (быстрее, чем через Octane).
- Проксирует API/SPA в Octane.
- Проксирует WebSocket в Reverb.

```bash
sudo nano /etc/nginx/sites-available/birhasap
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    root /var/www/birhasap/back/public;
    index index.php index.html;

    client_max_body_size 100M;

    # Статика (storage)
    location /storage/ {
        alias /var/www/birhasap/back/storage/app/public/;
        access_log off;
        expires 30d;
    }

    # WebSocket → Reverb
    location /app/ {
        proxy_pass http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400;
    }

    # Всё остальное → Octane
    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_redirect off;
        proxy_buffering off;
    }
}
```

Активировать:

```bash
sudo ln -s /etc/nginx/sites-available/birhasap /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### HTTPS через Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

Автообновление сертификатов уже настроено через systemd-таймер `certbot.timer`.

---

## 12. Доверенные прокси (важно!)

Octane стоит за Nginx, поэтому реальный IP клиента приходит в `X-Forwarded-For`. Без этого `request()->ip()`, HTTPS-детект и Sanctum cookies могут работать неправильно.

В `.env`:

```ini
TRUSTED_PROXIES=127.0.0.1
```

И проверить, что в `app/Http/Middleware/TrustProxies.php` стоит:

```php
protected $proxies = '*';   // или конкретный IP
protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO;
```

---

## 13. Команды для повседневного деплоя

```bash
cd /var/www/birhasap/back

# Pull нового кода
git pull --ff-only

# Зависимости
composer install --no-dev --optimize-autoloader

# Миграции
php artisan migrate --force

# Кеш
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Горячая перезагрузка воркеров (БЕЗ простоя)
php artisan octane:reload

# Перезапуск очередей (чтобы подхватили новый код)
sudo systemctl restart queue

# Reverb перезапускать только при изменении broadcast-логики
sudo systemctl restart reverb
```

---

## 14. Чек-лист после первого запуска

После того как Octane стартовал, обязательно проверить:

- [ ] Авторизация работает (вход/выход).
- [ ] Cookies/session не "залипают" между разными пользователями.
- [ ] `request()->user()` возвращает правильного юзера на каждом запросе.
- [ ] `ResolveCompanyContext` middleware корректно вытаскивает company_id для каждого запроса (а не использует значение от первого пришедшего).
- [ ] Локаль (язык) не залипает между запросами разных пользователей.
- [ ] Загрузка файлов (multipart) работает.
- [ ] Excel-импорт/экспорт не валит воркер по памяти.
- [ ] WebSocket (Reverb) принимает соединения.
- [ ] Очереди обрабатывают джобы.
- [ ] Память воркеров стабильна после 1000 запросов.

---

## 15. Что искать в коде, если что-то "залипает"

Главное правило Octane: **состояние одного запроса не должно жить дольше этого запроса**. Если что-то залипает, ищите:

### 15.1. Singleton с request-зависимым состоянием

```php
$this->app->singleton(SomeService::class, fn () => new SomeService(...));
// внутри SomeService есть $this->currentUser, $this->currentCompany — ОПАСНО
```

Решение: заменить `singleton` на `scoped`:

```php
$this->app->scoped(SomeService::class, fn () => new SomeService(...));
```

`scoped` живёт **только в рамках одного запроса** — Octane сбросит его автоматически.

### 15.2. Static с состоянием

```php
class CompanyContext {
    private static ?int $currentCompanyId = null;
}
```

Это будет **расшарено между всеми запросами**. Перенести в request, в контейнер (`scoped`), или в `Octane::flushState`.

### 15.3. Локальные кэши в синглтонах

```php
private array $cache = [];

public function find($id) {
    return $this->cache[$id] ??= Model::find($id);
}
```

Если сервис singleton — массив будет расти бесконечно. Использовать `Cache::remember()` через Redis.

### 15.4. Глобальный сброс состояния

В `app/Providers/AppServiceProvider.php` можно добавить:

```php
use Laravel\Octane\Facades\Octane;

public function boot(): void
{
    if (class_exists(Octane::class)) {
        Octane::flushState(function () {
            // принудительный сброс ваших singleton'ов между запросами
        });
    }
}
```

---

## 16. Откат на PHP-FPM (если что-то совсем плохо)

Octane — **дополнение**, не замена. Откатиться на классический FPM можно мгновенно:

```bash
sudo systemctl stop octane
sudo systemctl disable octane
```

В Nginx-конфиге заменить блок `location /` на стандартный для FPM:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
}
```

```bash
sudo systemctl reload nginx
```

Готово — приложение снова на FPM. Никаких миграций кода для отката не требуется.

---

## 17. Альтернатива FrankenPHP — RoadRunner

Если по какой-то причине FrankenPHP не подошёл (например, не работает HTTPS-фича или конфликт с прокси), вместо него можно RoadRunner:

```bash
php artisan octane:install --server=roadrunner
```

В `.env`:

```ini
OCTANE_SERVER=roadrunner
```

В systemd-сервисе заменить `--server=frankenphp` на `--server=roadrunner`. Всё остальное идентично.

---

## 18. Альтернатива всему — Docker

Если не хочется ставить PHP/Nginx руками, есть официальный образ FrankenPHP с Octane "из коробки":

```bash
docker run -d \
    --name birhasap \
    -p 80:80 -p 443:443 \
    -v /var/www/birhasap/back:/app \
    dunglas/frankenphp
```

Но для прод-конфигурации с очередями, Reverb и кронами проще остаться на systemd.

---

## 19. Полезные команды Octane

```bash
php artisan octane:start              # запуск (foreground)
php artisan octane:start --watch      # с авто-перезагрузкой (для dev, требует chokidar)
php artisan octane:reload             # горячая перезагрузка воркеров (без простоя)
php artisan octane:stop               # остановить
php artisan octane:status             # статус
```

---

## 20. Куда смотреть, если упало

1. `sudo journalctl -u octane -n 200 --no-pager` — логи systemd-сервиса.
2. `storage/logs/laravel.log` — логи приложения.
3. `sudo journalctl -u reverb -n 100` — логи Reverb.
4. `sudo journalctl -u queue -n 100` — логи очередей.
5. `sudo nginx -t && sudo journalctl -u nginx -n 100` — логи Nginx.

Для локального запуска через WSL без сервисов:

1. Вывод текущего процесса `php artisan octane:start` в терминале.
2. `storage/logs/laravel.log` — логи приложения.
3. `php artisan octane:status` — проверка состояния Octane.

---

## Итоговая последовательность для быстрого деплоя

```bash
# 1. Системные пакеты + PHP
sudo apt update && sudo apt install -y curl wget git unzip nginx redis-server supervisor
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-common php8.3-fpm php8.3-mysql php8.3-redis \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath php8.3-intl \
    php8.3-zip php8.3-gd php8.3-imagick php8.3-opcache

# 2. Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 3. Проект
cd /var/www && git clone <REPO> birhasap && cd birhasap/back
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env

# 4. Миграции и кеш
php artisan key:generate
php artisan migrate --force
php artisan config:cache route:cache view:cache event:cache
sudo chown -R www-data:www-data storage bootstrap/cache

# 5. Octane
php artisan octane:install --server=frankenphp

# 6. Тестовый запуск
php artisan octane:start --server=frankenphp --port=8000 --workers=4

# 7. systemd-сервисы (см. раздел 10)
# 8. Nginx + HTTPS (см. раздел 11)
```

---

## Локальный запуск через WSL2 (быстрый путь)

```bash
cd /mnt/d/ospanel/domains/birhasap/back
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan octane:install --server=frankenphp
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=4
```

Проверка из Windows-браузера: `http://localhost:8000`.
