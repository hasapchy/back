<?php

return [
    'resources' => [
        'warehouses' => [
            'has_user_id' => false,
            'check_strategy' => 'many_to_many',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_stocks' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view'],
            'scope_actions' => ['view'],
        ],
        'warehouse_receipts' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_writeoffs' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_movements' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'categories' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'products' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'clients' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'cash_registers' => [
            'has_user_id' => false,
            'check_strategy' => 'many_to_many',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'projects' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'project_statuses' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'sales' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'transactions' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
            'custom_permissions' => [
                'view_sale' => 'transactions_view_sale',
                'view_order' => 'transactions_view_order',
                'view_receipt' => 'transactions_view_receipt',
                'view_salary' => 'transactions_view_salary',
                'view_other' => 'transactions_view_other',
            ],
        ],
        'mutual_settlements' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view'],
            'scope_actions' => ['view'],
            'custom_permissions' => [
                'view_individual' => 'mutual_settlements_view_individual',
                'view_company' => 'mutual_settlements_view_company',
                'view_employee' => 'mutual_settlements_view_employee',
                'view_investor' => 'mutual_settlements_view_investor',
            ],
        ],
        'transfers' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'orders' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'order_statuses' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'order_statuscategories' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'transaction_categories' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'invoices' => [
            'has_user_id' => true,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'users' => [
            'has_user_id' => false,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'roles' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'companies' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'currency_history' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'employee_salaries' => [
            'has_user_id' => false,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'tasks' => [
            'has_user_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
    ],

    'custom_permissions' => [
        'settings_edit_any_date',
        'settings_project_budget_view',
        'settings_project_files_view',
        'settings_project_balance_view',
        'settings_project_contracts_view',
        'settings_currencies_view',
        'settings_cash_balance_view',
        'settings_client_balance_view',
        'settings_client_balance_adjustment',
        'products_create_temp',
    ],

    'groups' => [
        'finance' => [
            'label' => 'finance',
            'resources' => ['transactions', 'mutual_settlements', 'transaction_categories', 'invoices', 'cash_registers', 'transfers'],
        ],
        'warehouses' => [
            'label' => 'warehouses',
            'resources' => ['warehouses', 'warehouse_stocks', 'warehouse_receipts', 'warehouse_writeoffs', 'warehouse_movements'],
        ],
        'orders' => [
            'label' => 'orders',
            'resources' => ['orders', 'order_statuses', 'order_statuscategories'],
        ],
        'products' => [
            'label' => 'products',
            'resources' => ['products', 'categories'],
        ],
        'clients' => [
            'label' => 'clients',
            'resources' => ['clients'],
        ],
        'sales' => [
            'label' => 'sales',
            'resources' => ['sales'],
        ],
        'projects' => [
            'label' => 'projects',
            'resources' => ['projects', 'project_statuses'],
        ],
        'companies' => [
            'label' => 'companies',
            'resources' => ['companies'],
        ],
        'currency_history' => [
            'label' => 'currency_history',
            'resources' => ['currency_history'],
        ],
        'users' => [
            'label' => 'users',
            'resources' => ['users', 'roles', 'employee_salaries'],
        ],
        'tasks' => [
            'label' => 'tasks',
            'resources' => ['tasks'],
        ],
    ],

    'group_custom_permissions' => [
        'projects' => [
            'settings_project_budget_view',
            'settings_project_balance_view',
            'settings_project_files_view',
            'settings_project_contracts_view',
        ],
        'finance' => [
            'settings_cash_balance_view',
            'settings_currencies_view',
        ],
        'clients' => [
            'settings_client_balance_view',
            'settings_client_balance_adjustment',
        ],
    ],
];
