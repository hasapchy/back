<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Локальный Node push-service (hasapchy-push-service)
    |--------------------------------------------------------------------------
    | База без завершающего слэша, например: http://192.168.1.10:8787
    | Маршруты сервиса: GET /health, POST /v1/push/send
    */
    'base_url' => env('PUSH_SERVICE_BASE_URL', ''),

    /*
    | Должен совпадать с PUSH_SERVICE_API_KEY в .env самого push-service.
    */
    'api_key' => env('PUSH_SERVICE_API_KEY', ''),

    'timeout' => (int) env('PUSH_SERVICE_TIMEOUT', 15),
];
