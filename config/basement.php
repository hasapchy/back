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
    | Default Company ID
    |--------------------------------------------------------------------------
    |
    | Default company ID for basement operations when no company is specified.
    | This should be used carefully and preferably replaced with proper
    | company selection logic.
    |
    */
    'default_company_id' => env('BASEMENT_DEFAULT_COMPANY_ID', 1),

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
    | Basement Products Category Filter
    |--------------------------------------------------------------------------
    |
    | Category ID to filter products for basement workers.
    | Set to null to disable filtering.
    |
    */
    'products_category_filter' => env('BASEMENT_PRODUCTS_CATEGORY_FILTER', 1),

    /*
    |--------------------------------------------------------------------------
    | Default Cash Register ID
    |--------------------------------------------------------------------------
    |
    | Default cash register ID for basement workers.
    |
    */
    'default_cash_register_id' => env('BASEMENT_DEFAULT_CASH_REGISTER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Default Warehouse ID
    |--------------------------------------------------------------------------
    |
    | Default warehouse ID for basement workers.
    |
    */
    'default_warehouse_id' => env('BASEMENT_DEFAULT_WAREHOUSE_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | User to Category Mapping
    |--------------------------------------------------------------------------
    |
    | Maps user IDs to their default category IDs for basement workers.
    | If a user is not in this map, the first available category from
    | category_users table will be used.
    |
    */
    'user_category_map' => [
        6 => 2,
        7 => 3,
        8 => 14,
        12 => 14,
    ],
];
