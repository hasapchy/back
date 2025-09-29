<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Basement System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the basement system including time limits
    | and access restrictions for basement workers.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Order Time Limits
    |--------------------------------------------------------------------------
    |
    | Time limits for basement workers to edit or delete orders after creation.
    | Values are in hours.
    |
    */
    'order_edit_limit_hours' => env('BASEMENT_ORDER_EDIT_LIMIT_HOURS', 8),
    'order_delete_limit_hours' => env('BASEMENT_ORDER_DELETE_LIMIT_HOURS', 8),

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
];
