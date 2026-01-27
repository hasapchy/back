<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Simple System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the simple system and access restrictions
    | for simple workers.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Simple Worker Role
    |--------------------------------------------------------------------------
    |
    | Role name for simple workers.
    |
    */
    'worker_role' => 'basement_worker',

    /*
    |--------------------------------------------------------------------------
    | Default Cash Register ID
    |--------------------------------------------------------------------------
    |
    | Default cash register ID for simple workers.
    | If not set, the first available cash register will be used.
    |
    */
    'default_cash_register_id' => env('BASEMENT_DEFAULT_CASH_REGISTER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default Warehouse ID
    |--------------------------------------------------------------------------
    |
    | Default warehouse ID for simple workers.
    | If not set, the first available warehouse will be used.
    |
    */
    'default_warehouse_id' => env('BASEMENT_DEFAULT_WAREHOUSE_ID'),

    /*
    |--------------------------------------------------------------------------
    | User to Category Mapping
    |--------------------------------------------------------------------------
    |
    | Static mapping of user IDs to category IDs for simple workers.
    | Format: 'user_id' => 'category_id'
    | Multiple users can map to the same category.
    |
    */
    'user_category_mapping' => [
        12 => 14,
        8 => 14,
        6 => 2,
        7 => 3,
    ],
];
