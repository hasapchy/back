# JWT Настройки и Refresh Token

## Обзор изменений

Проект был обновлен для улучшения работы с JWT токенами и добавления системы refresh token.

## Настройки JWT

### Backend (Laravel)

#### 1. Время жизни токенов
```php
// config/jwt.php
'ttl' => env('JWT_TTL', 120),           // Access Token: 120 минут (2 часа)
'refresh_ttl' => env('JWT_REFRESH_TTL', 43200), // Refresh Token: 30 дней
```

#### 2. Переменные окружения (.env)
```env
JWT_TTL=120          # Время жизни access token в минутах
JWT_REFRESH_TTL=43200 # Время жизни refresh token в минутах
JWT_SECRET=your_jwt_secret_key
JWT_BLACKLIST_ENABLED=true
JWT_BLACKLIST_GRACE_PERIOD=0
```

### Frontend (Vue.js)

#### 1. Хранение токенов
```javascript
// Токены сохраняются в localStorage с временем истечения
localStorage.setItem('token', data.access_token);
localStorage.setItem('refreshToken', data.refresh_token);
localStorage.setItem('tokenExpiresAt', now + (data.expires_in * 1000));
localStorage.setItem('refreshTokenExpiresAt', now + (data.refresh_expires_in * 1000));
```

#### 2. Автоматическое обновление
- Проверка истечения токена перед каждым запросом
- Автоматическое обновление при получении 401 ошибки
- Предварительное обновление за 30 секунд до истечения

## API Endpoints

### 1. Логин
```http
POST /api/user/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}
```

**Ответ:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "bearer",
  "expires_in": 7200,
  "refresh_expires_in": 2592000,
  "user": {
    "id": 1,
    "name": "User Name",
    "email": "user@example.com",
    "is_admin": true,
    "permissions": ["users_view", "products_view"]
  }
}
```

### 2. Обновление токена
```http
POST /api/user/refresh
Content-Type: application/json

{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
}
```

**Ответ:** Аналогичен логину с новыми токенами

### 3. Выход
```http
POST /api/user/logout
Authorization: Bearer {access_token}
```

**Ответ:**
```json
{
  "message": "Successfully logged out",
  "status": "success"
}
```

## Безопасность

### 1. Черный список токенов
- JWT blacklist включен по умолчанию
- Токены добавляются в черный список при выходе
- Grace period: 0 секунд

### 2. Проверки
- Валидация refresh token перед обновлением
- Проверка активности пользователя
- Автоматическая очистка истекших токенов

### 3. Хранение
- Токены хранятся в localStorage
- Время истечения проверяется перед каждым запросом
- Автоматическая очистка при ошибках

## Компоненты

### TokenStatusComponent
Отображает статус токенов и позволяет:
- Просматривать время до истечения
- Ручное обновление токенов
- Мониторинг состояния

### TokenUtils
Утилиты для работы с токенами:
- Проверка истечения
- Форматирование времени
- Очистка данных

## Мониторинг

### 1. Автоматические проверки
- Каждые 30 секунд проверяется статус токенов
- Предварительное обновление за 30 секунд до истечения
- Логирование всех операций с токенами

### 2. Уведомления
- Цветовая индикация статуса токенов
- Сообщения об успешном обновлении
- Уведомления об ошибках

## Рекомендации

### 1. Продакшн
```env
JWT_TTL=60           # 1 час для access token
JWT_REFRESH_TTL=10080 # 7 дней для refresh token
JWT_BLACKLIST_GRACE_PERIOD=30 # 30 секунд grace period
```

### 2. Разработка
```env
JWT_TTL=120          # 2 часа для access token
JWT_REFRESH_TTL=43200 # 30 дней для refresh token
JWT_BLACKLIST_GRACE_PERIOD=0
```

### 3. Тестирование
```env
JWT_TTL=5            # 5 минут для быстрого тестирования
JWT_REFRESH_TTL=60   # 1 час для refresh token
```

## Troubleshooting

### 1. Токен не обновляется
- Проверьте время жизни refresh token
- Убедитесь, что JWT_BLACKLIST_ENABLED=true
- Проверьте логи Laravel

### 2. Частые 401 ошибки
- Увеличьте JWT_TTL
- Проверьте настройки часовых поясов
- Убедитесь в правильности JWT_SECRET

### 3. Проблемы с localStorage
- Проверьте права доступа к localStorage
- Убедитесь в поддержке браузером
- Проверьте настройки приватного режима

## Логирование

### Laravel
```php
// В .env
LOG_LEVEL=debug

// В AuthController добавьте логирование
Log::info('Token refreshed for user', ['user_id' => $user->id]);
Log::warning('Failed token refresh', ['error' => $e->getMessage()]);
```

### Frontend
```javascript
// В консоли браузера
console.log('Token status:', TokenUtils.getAccessTokenTimeLeft());
console.log('Refresh needed:', TokenUtils.needsRefresh());
```
