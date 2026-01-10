# Настройка Nginx для Hasapchy проекта

## Установка конфигурации

1. **Скопируйте конфиг на сервер:**
   ```bash
   sudo cp nginx-hasapchy.conf /etc/nginx/sites-available/test.hasapp.online
   ```

2. **Создайте симлинк:**
   ```bash
   sudo ln -s /etc/nginx/sites-available/test.hasapp.online /etc/nginx/sites-enabled/
   ```

3. **Проверьте синтаксис:**
   ```bash
   sudo nginx -t
   ```

4. **Перезагрузите nginx:**
   ```bash
   sudo systemctl reload nginx
   ```

## Настройка путей

Убедитесь, что пути в конфиге соответствуют вашей структуре:

- **Фронтенд:** `/var/www/testHasap/front/dist`
- **Бэкенд:** `/var/www/testHasap/back/public`
- **Storage:** `/var/www/testHasap/back/public/storage`
- **PHP-FPM:** `/var/run/php/php8.3-fpm.sock` (проверьте версию PHP)

## Настройка Reverb сервера

1. **В `back/.env` установите:**
   ```env
   BROADCAST_DRIVER=reverb
   
   # Reverb Server (запускается локально)
   REVERB_SERVER_HOST=0.0.0.0
   REVERB_SERVER_PORT=8080
   
   # Reverb App credentials
   REVERB_APP_ID=hasapchy-app
   REVERB_APP_KEY=hasapchy-key
   REVERB_APP_SECRET=hasapchy-secret
   
   # Reverb Host/Port для клиентов (через nginx)
   REVERB_HOST=test.hasapp.online
   REVERB_PORT=443
   REVERB_SCHEME=https
   REVERB_SERVER_PATH=/app
   ```

2. **Запустите Reverb сервер:**
   ```bash
   cd /var/www/testHasap/back
   php artisan reverb:start --host=0.0.0.0 --port=8080
   ```

   **Для постоянной работы используйте supervisor или systemd.**

3. **Создайте systemd service (опционально):**
   ```bash
   sudo nano /etc/systemd/system/reverb.service
   ```
   
   Содержимое:
   ```ini
   [Unit]
   Description=Laravel Reverb Server
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/var/www/testHasap/back
   ExecStart=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
   Restart=always
   RestartSec=3

   [Install]
   WantedBy=multi-user.target
   ```
   
   Затем:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable reverb
   sudo systemctl start reverb
   ```

## Настройка фронтенда

В `front/.env` (или в production build):
```env
VITE_APP_BASE_URL=https://test.hasapp.online
VITE_REVERB_APP_KEY=hasapchy-key
VITE_REVERB_HOST=test.hasapp.online
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

**Важно:** После изменения `.env` пересоберите фронтенд:
```bash
cd /var/www/testHasap/front
npm run build
```

## Проверка работы

1. **Проверьте WebSocket подключение:**
   - Откройте сайт в браузере
   - Откройте консоль разработчика (F12)
   - Должны быть сообщения о подключении WebSocket
   - URL должен быть: `wss://test.hasapp.online/app`

2. **Проверьте API:**
   ```bash
   curl https://test.hasapp.online/api
   ```

3. **Проверьте Reverb сервер:**
   ```bash
   sudo netstat -tlnp | grep 8080
   # Должен показать: tcp 0.0.0.0:8080 LISTEN
   ```

4. **Проверьте логи nginx:**
   ```bash
   sudo tail -f /var/log/nginx/test.hasapp.online.access.log
   sudo tail -f /var/log/nginx/test.hasapp.online.error.log
   ```

## Troubleshooting

### WebSocket не подключается

1. Проверьте, что Reverb сервер запущен:
   ```bash
   sudo systemctl status reverb
   # или
   ps aux | grep reverb
   ```

2. Проверьте порт 8080:
   ```bash
   sudo netstat -tlnp | grep 8080
   ```

3. Проверьте логи Reverb:
   ```bash
   # Если запущен через artisan
   # Логи будут в терминале где запущен reverb:start
   ```

4. Проверьте nginx логи:
   ```bash
   sudo tail -f /var/log/nginx/test.hasapp.online.error.log | grep app
   ```

### 502 Bad Gateway

1. Проверьте PHP-FPM:
   ```bash
   sudo systemctl status php8.3-fpm
   ```

2. Проверьте путь к PHP-FPM socket:
   ```bash
   ls -la /var/run/php/php8.3-fpm.sock
   ```

3. Проверьте права доступа:
   ```bash
   sudo chown www-data:www-data /var/www/testHasap/back/storage -R
   sudo chmod 775 /var/www/testHasap/back/storage -R
   ```

### CORS ошибки

Убедитесь, что в `back/config/cors.php` разрешены правильные origins:
```php
'allowed_origins' => ['https://test.hasapp.online'],
```

## Безопасность

1. **Ограничьте доступ к API (опционально):**
   ```nginx
   # В location /api добавить:
   # allow 192.168.0.0/16;
   # deny all;
   ```

2. **Настройте rate limiting:**
   ```nginx
   limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
   
   location /api {
       limit_req zone=api_limit burst=20;
       # ... остальная конфигурация
   }
   ```

3. **Регулярно обновляйте SSL сертификаты:**
   ```bash
   sudo certbot renew --dry-run
   ```

