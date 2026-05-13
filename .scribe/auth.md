# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

<p><strong>Вход:</strong> <code>GET /sanctum/csrf-cookie</code> (опционально), <code>POST /api/user/login</code> с cookies. Ответ: <code>user</code>, сессия в httpOnly cookie.</p><p><strong>API:</strong> <code>credentials: include</code>, префикс <code>api/*</code> без проверки CSRF (защита SameSite).</p>
