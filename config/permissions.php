<?php

return [
    'resources' => [
        'warehouses' => [
            'has_creator_id' => false,
            'check_strategy' => 'many_to_many',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_stocks' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'export'],
            'scope_actions' => ['view'],
        ],
        'warehouse_receipts' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_purchases' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_writeoffs' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'warehouse_movements' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'inventories' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'delete', 'export'],
            'scope_actions' => ['view', 'delete'],
            'custom_permissions' => [
                'count' => 'inventories_count',
                'finalize' => 'inventories_finalize',
            ],
        ],
        'categories' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'units' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => [],
        ],
        'products' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
            'custom_permissions' => [
                'create_temp' => 'products_create_temp',
            ],
        ],
        'clients' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'client_balances' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'cash_registers' => [
            'has_creator_id' => false,
            'check_strategy' => 'many_to_many',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'projects' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'project_statuses' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'sales' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'transactions' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
            'custom_permissions' => [
                'view_sale' => 'transactions_view_sale',
                'view_order' => 'transactions_view_order',
                'view_receipt' => 'transactions_view_receipt',
                'view_purchase' => 'transactions_view_purchase',
                'view_salary' => 'transactions_view_salary',
                'view_other' => 'transactions_view_other',
            ],
        ],
        'reports' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view'],
            'scope_actions' => [],
            'custom_permissions' => [
                'view_by_categories' => 'reports_view_by_categories',
            ],
        ],
        'transaction_templates' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'mutual_settlements' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'export'],
            'scope_actions' => ['view'],
            'custom_permissions' => [
                'view_individual' => 'mutual_settlements_view_individual',
                'view_company' => 'mutual_settlements_view_company',
                'view_employee' => 'mutual_settlements_view_employee',
                'view_investor' => 'mutual_settlements_view_investor',
            ],
        ],
        'transfers' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'orders' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'orders_simple' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'leads' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'lead_statuses' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'lead_sources' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'order_statuses' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'order_statuscategories' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'transaction_categories' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'invoices' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'users' => [
            'has_creator_id' => false,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'roles' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'companies' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'holidays' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'production_calendar' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'currencies' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view'],
            'scope_actions' => ['view'],
        ],
        'currency_history' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'employee_salaries' => [
            'has_creator_id' => false,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
            'custom_permissions' => [
                'accrue' => 'employee_salaries_accrue',
            ],
        ],
        'tasks' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'task_statuses' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'leave_types' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'leaves' => [
            'has_creator_id' => false,
            'check_strategy' => 'user_id',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'contracts' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'require_owner_for_own' => true,
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'chats' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view'],
            'custom_permissions' => [
                'write' => 'chats_write',
                'write_general' => 'chats_write_general',
                'group_create' => 'chats_group_create',
            ],
        ],
        'news' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['create', 'update', 'delete'],
            'scope_actions' => ['update', 'delete'],
        ],
        'templates' => [
            'has_creator_id' => false,
            'check_strategy' => 'default',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'departments' => [
            'has_creator_id' => false,
            'check_strategy' => 'many_to_many',
            'actions' => ['view', 'create', 'update', 'delete', 'export'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
        'drive' => [
            'has_creator_id' => true,
            'check_strategy' => 'creator_id',
            'actions' => ['view', 'create', 'update', 'delete'],
            'scope_actions' => ['view', 'update', 'delete'],
        ],
    ],

    'custom_permissions' => [
        'settings_edit_any_date',
        'settings_project_budget_view',
        'settings_project_balance_view',
        'settings_project_contracts_view',
        'settings_currencies_view',
        'settings_cash_balance_view',
        'settings_client_balance_view',
        'settings_client_balance_view_own',
        'settings_client_balance_view_cash',
        'settings_client_balance_view_non_cash',
        'settings_client_balance_adjustment',
        'products_create_temp',
        'settings_transaction_category_bindings_view',
        'settings_transaction_category_bindings_edit',
    ],

    'groups' => [
        'finance' => [
            'label' => 'finance',
            'resources' => ['transactions', 'transaction_templates', 'mutual_settlements', 'transaction_categories', 'invoices', 'cash_registers', 'transfers', 'reports'],
        ],
        'warehouses' => [
            'label' => 'warehouse',
            'resources' => ['warehouses', 'warehouse_stocks', 'warehouse_receipts', 'warehouse_purchases', 'warehouse_writeoffs', 'warehouse_movements', 'inventories'],
        ],
        'orders' => [
            'label' => 'orders',
            'resources' => ['orders', 'order_statuses', 'order_statuscategories'],
        ],
        'leads' => [
            'label' => 'leads',
            'resources' => ['leads', 'lead_statuses', 'lead_sources'],
        ],
        'orders_simple' => [
            'label' => 'orders_simple',
            'resources' => ['orders_simple'],
        ],
        'products' => [
            'label' => 'products',
            'resources' => ['products', 'categories', 'units'],
        ],
        'clients' => [
            'label' => 'clients',
            'resources' => ['clients', 'client_balances'],
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
            'resources' => ['companies', 'holidays', 'production_calendar'],
        ],
        'currencies' => [
            'label' => 'currencies',
            'resources' => ['currencies', 'currency_history'],
        ],
        'users' => [
            'label' => 'users',
            'resources' => ['users', 'roles', 'employee_salaries', 'departments'],
        ],
        'tasks' => [
            'label' => 'tasks',
            'resources' => ['tasks', 'task_statuses'],
        ],
        'leaves' => [
            'label' => 'leaves',
            'resources' => ['leaves', 'leave_types'],
        ],
        'departments' => [
            'label' => 'departments',
            'resources' => ['departments'],
        ],
        'chats' => [
            'label' => 'chats',
            'resources' => ['chats'],
        ],
        'news' => [
            'label' => 'news',
            'resources' => ['news', 'templates'],
        ],
        'drive' => [
            'label' => 'drive',
            'resources' => ['drive'],
        ],
    ],

    'group_custom_permissions' => [
        'projects' => [
            'settings_project_budget_view',
            'settings_project_balance_view',
            'settings_project_contracts_view',
        ],
        'finance' => [
            'settings_cash_balance_view',
            'settings_currencies_view',
            'reports_view_by_categories',
        ],
        'clients' => [
            'settings_client_balance_view',
            'settings_client_balance_view_own',
            'settings_client_balance_view_cash',
            'settings_client_balance_view_non_cash',
            'settings_client_balance_adjustment',
        ],
        'companies' => [
            'settings_transaction_category_bindings_view',
            'settings_transaction_category_bindings_edit',
        ],
    ],
];
