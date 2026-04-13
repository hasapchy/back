# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

**Web SPA (stateful domain):** `GET /sanctum/csrf-cookie`, затем `POST /api/user/login` с cookies и заголовком `X-CSRF-TOKEN` (значение cookie `XSRF-TOKEN` как есть). В ответе есть `user`, без `access_token`.

**Mobile / token:** `POST /api/user/login` без stateful Origin/Referer — в ответе `access_token`, `refresh_token`, далее Bearer и `POST /api/user/refresh`.

**Важно:** заголовок `X-Company-ID`.
