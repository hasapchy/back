<?php

return [
    'schema_version' => 1,
    'max_patch_bytes' => 262144,
    'vuex_fields' => [
        'uiTheme',
        'soundEnabled',
        'menuItems',
        'kanbanCardFields',
        'kanbanCardFieldDateModes',
        'viewModes',
        'cardGridColumns',
        'newsFilters',
    ],
    'ls_prefixes' => [
        'tableColumns_',
        'tableSort_',
        'cardFields_',
        'ui_cash_register_user_colors_',
        'ui_transactions_balance_cards_layout_',
        'cardGridColumns_',
    ],
    'ls_exact_keys' => [
        'kanban_column_order_orders',
        'kanban_column_order_projects',
        'kanban_column_order_tasks',
        'kanban_column_order_leads',
        'perPage',
        'reportByCategoriesFilters',
    ],
    'ls_prefixes_with_user_id' => [
        'simple_services_order_',
    ],
];
