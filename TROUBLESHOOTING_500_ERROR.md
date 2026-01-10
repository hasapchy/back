# Диагностика ошибки 500 при отправке сообщения в чат

## Проблема
При отправке сообщения в чат получаете ошибку 500 Internal Server Error.

## Возможные причины

### 1. Reverb сервер не запущен или недоступен

**Проверка:**
```bash
# Проверьте, запущен ли Reverb сервер
sudo netstat -tlnp | grep 8080
# или
ps aux | grep reverb
```

**Решение:**
```bash
cd /var/www/testHasap/back
php artisan reverb:start --host=0.0.0.0 --port=8080
```

### 2. Неправильная конфигурация Broadcasting

**Проверьте `back/.env`:**
```env
BROADCAST_DRIVER=reverb
REVERB_APP_ID=hasapchy-app
REVERB_APP_KEY=hasapchy-key
REVERB_APP_SECRET=hasapchy-secret
REVERB_HOST=test.hasapp.online
REVERB_PORT=443
REVERB_SCHEME=https
```

**Важно:** Если Reverb сервер не запущен, временно установите:
```env
BROADCAST_DRIVER=null
```
Это отключит broadcasting, но сообщения будут сохраняться.

### 3. Проблемы с правами доступа к storage

**Проверка:**
```bash
ls -la /var/www/testHasap/back/storage
ls -la /var/www/testHasap/back/public/storage
```

**Решение:**
```bash
sudo chown -R www-data:www-data /var/www/testHasap/back/storage
sudo chmod -R 775 /var/www/testHasap/back/storage
sudo chown -R www-data:www-data /var/www/testHasap/back/public/storage
sudo chmod -R 775 /var/www/testHasap/back/public/storage
```

### 4. Ошибка при сохранении файлов

Если отправляете файлы вместе с сообщением, проверьте:
- Существует ли директория `storage/app/public/chats/`
- Правильные ли права доступа

**Решение:**
```bash
mkdir -p /var/www/testHasap/back/storage/app/public/chats
sudo chown -R www-data:www-data /var/www/testHasap/back/storage/app/public/chats
sudo chmod -R 775 /var/www/testHasap/back/storage/app/public/chats
```

### 5. Проблемы с базой данных

**Проверка:**
```bash
cd /var/www/testHasap/back
php artisan migrate:status
```

**Решение:**
```bash
php artisan migrate
```

## Как проверить логи ошибок

### Laravel логи:
```bash
tail -f /var/www/testHasap/back/storage/logs/laravel.log
```

### Nginx логи:
```bash
sudo tail -f /var/log/nginx/test.hasapp.online.error.log
```

### PHP-FPM логи:
```bash
sudo tail -f /var/log/php8.3-fpm.log
```

## Временное решение

Если нужно быстро исправить проблему, временно отключите broadcasting:

1. В `back/.env` установите:
   ```env
   BROADCAST_DRIVER=null
   ```

2. Очистите кэш конфигурации:
   ```bash
   cd /var/www/testHasap/back
   php artisan config:clear
   php artisan cache:clear
   ```

3. Перезапустите PHP-FPM:
   ```bash
   sudo systemctl restart php8.3-fpm
   ```

**Примечание:** Сообщения будут сохраняться, но не будут отправляться через WebSocket в реальном времени.

## Постоянное решение

1. Убедитесь, что Reverb сервер запущен и работает
2. Проверьте все переменные окружения в `.env`
3. Проверьте права доступа к storage
4. Проверьте логи на наличие конкретных ошибок

## Проверка после исправления

1. Отправьте тестовое сообщение
2. Проверьте логи на наличие ошибок
3. Проверьте, что сообщение сохранилось в базе данных
4. Проверьте, что WebSocket соединение активно (в консоли браузера)

