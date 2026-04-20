<?php

return [
    'fcm_mirror' => (bool) env('IN_APP_NOTIFICATIONS_FCM_MIRROR', false),

    'channels' => [
        'orders_new' => [
            'any_permissions' => ['orders_view_all', 'orders_simple_view_all'],
        ],
        'clients_new' => [
            'any_permissions' => ['clients_view_all', 'clients_view'],
        ],
        'sales_new' => [
            'any_permissions' => ['sales_view_all'],
        ],
        'transactions_new' => [
            'any_permissions' => ['transactions_view_all', 'transactions_view'],
        ],
        'chats_new_message' => [
            'any_permissions' => ['chats_view_all', 'chats_view'],
        ],
        'tasks_new' => [
            'any_permissions' => ['tasks_view_all'],
        ],
        'news_new' => [
            'all_company_members' => true,
        ],
        'birthdays_today' => [
            'all_company_members' => true,
        ],
        'leaves_new' => [
            'any_permissions' => ['leaves_view_all'],
        ],
    ],
];
