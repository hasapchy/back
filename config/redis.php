<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],

    'session' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_SESSION_DB', '2'),
    ],

    'queue' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_QUEUE_DB', '3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Options
    |--------------------------------------------------------------------------
    |
    | Here you may define additional Redis options for your application.
    | These options are used when connecting to Redis.
    |
    */

    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', ''),
        'serializer' => env('REDIS_SERIALIZER', 'php'),
        'read_timeout' => env('REDIS_READ_TIMEOUT', 60),
        'retry_interval' => env('REDIS_RETRY_INTERVAL', 1000),
        'compression' => env('REDIS_COMPRESSION', 'none'),
        'lazy' => env('REDIS_LAZY', false),
        'persistent' => env('REDIS_PERSISTENT', false),
        'tcp_keepalive' => env('REDIS_TCP_KEEPALIVE', 0),
        'tcp_nodelay' => env('REDIS_TCP_NODELAY', true),
    ],

];
