# WebSocket Setup с Laravel Reverb

## 1. Настройка Backend (.env)

Откройте `C:\ospanel\domains\Hasapchy\back\.env` и добавьте/измените:

```env
# Broadcasting
BROADCAST_DRIVER=reverb

# Reverb Server (запускается через php artisan reverb:start)
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Reverb App credentials (для авторизации)
REVERB_APP_ID=hasapchy-app
REVERB_APP_KEY=hasapchy-key
REVERB_APP_SECRET=hasapchy-secret

# Reverb Host/Port для клиентов (фронтенд подключается сюда)
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

## 2. Настройка Frontend (.env)

Откройте `C:\ospanel\domains\Hasapchy\front\.env` и добавьте/измените:

```env
# Backend API
VITE_APP_BASE_URL=http://192.168.50.71

# WebSocket (Reverb использует Pusher protocol)
VITE_PUSHER_APP_KEY=hasapchy-key
VITE_PUSHER_APP_CLUSTER=mt1
VITE_PUSHER_HOST=127.0.0.1
VITE_PUSHER_PORT=8080
VITE_PUSHER_SCHEME=http
```

## 3. Запуск Reverb сервера

Откройте новый терминал и выполните:

```bash
cd C:\ospanel\domains\Hasapchy\back
php artisan reverb:start --debug
```

Вы увидите:
```
INFO  Reverb server started on 127.0.0.1:8080
```

## 4. Перезапустить фронтенд

```bash
cd C:\ospanel\domains\Hasapchy\front
npm run dev
```

## 5. Проверка работы

1. Откройте браузер (пользователь A)
2. Откройте другой браузер/инкогнито (пользователь B)
3. Войдите под разными пользователями
4. Откройте Messenger
5. Пользователь A пишет пользователю B
6. Сообщение должно появиться у B **мгновенно**

В консоли браузера увидите:
```
[WebSocket] Подписка на канал: company.1.chat.2
[WebSocket] Получено новое сообщение: {...}
```

В терминале Reverb увидите:
```
Subscribing to company.1.chat.2
Broadcasting message...
```

## Преимущества Reverb

- ✅ Работает на PHP (не нужен Node.js 14-18)
- ✅ Официальный от Laravel
- ✅ Совместим с Pusher protocol
- ✅ Простая настройка
- ✅ Нет зависимости от внешних сервисов

## Альтернатива: Docker Soketi

Если хотите использовать Soketi через Docker (без проблем с Node.js):

```bash
docker run -p 6001:6001 -e SOKETI_DEFAULT_APP_ID=hasapchy-app -e SOKETI_DEFAULT_APP_KEY=hasapchy-key -e SOKETI_DEFAULT_APP_SECRET=hasapchy-secret quay.io/soketi/soketi:latest-16-alpine
```

Тогда в .env используйте `REVERB_PORT=6001` (или PUSHER_PORT=6001).

