<?php

return [
    'conversion_cycle' => 'This link would create a cycle in the packaging graph.',
    'conversion_reverse_exists' => 'The inverse relationship between these units already exists.',
    'conversion_parent_equals_child' => 'Parent and child units must differ.',
    'conversion_quantity_positive' => 'Quantity must be greater than zero.',
    'conversion_conflicting_paths' => 'A different factor for these units is already implied by existing links.',
    'conversion_path_quantity_mismatch' => 'Quantity does not match the factor implied by existing links.',
    'delete_in_use_by_products' => 'This unit is assigned to products and cannot be deleted.',
    'delete_in_use_by_conversions' => 'This unit is used in product packaging links and cannot be deleted.',
    'invalid_unit_catalog_scope' => 'Pick units from your company catalog.',
    'system_unit_readonly' => 'System reference units cannot be renamed or deleted.',
];
