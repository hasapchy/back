<?php

return [
    'not_found' => 'Purchase not found',
    'created_success' => 'Purchase created',
    'updated_success' => 'Purchase updated',
    'deleted_success' => 'Purchase deleted',
    'edit_only_draft' => 'Only draft purchase can be edited',
    'delete_only_draft' => 'Only draft purchase can be deleted',
    'delete_forbidden_has_receipts' => 'Cannot delete purchase with linked receipts',
    'goods_payment_exceeds_remaining' => 'The goods payment amount cannot exceed the remaining balance for this purchase in the base currency.',
    'receipt_product_not_available' => 'This product is no longer available for receipt: the full purchase quantity has already been received.',
    'receipt_quantity_exceeds_remaining' => 'Receipt quantity cannot exceed the remaining quantity on the purchase.',
    'receipt_price_must_match_purchase' => 'Receipt price from a purchase must match the purchase price.',
];
