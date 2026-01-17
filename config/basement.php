<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Basement System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the basement system and access restrictions
    | for basement workers.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Basement Worker Role
    |--------------------------------------------------------------------------
    |
    | Role name for basement workers.
    |
    */
    'worker_role' => 'basement_worker',

    /*
    |--------------------------------------------------------------------------
    | Default Cash Register ID
    |--------------------------------------------------------------------------
    |
    | Default cash register ID for basement workers.
    | If not set, the first available cash register will be used.
    |
    */
    'default_cash_register_id' => env('BASEMENT_DEFAULT_CASH_REGISTER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default Warehouse ID
    |--------------------------------------------------------------------------
    |
    | Default warehouse ID for basement workers.
    | If not set, the first available warehouse will be used.
    |
    */
    'default_warehouse_id' => env('BASEMENT_DEFAULT_WAREHOUSE_ID'),
];
