<?php

return [
    'receipt_linked_to_purchase' => 'Cannot return goods for a receipt linked to a purchase',
    'receipt_status_not_eligible' => 'Return is only available for approved or completed receipts',
    'receipt_supplier_required' => 'Receipt must have a supplier',
    'receipt_cash_required' => 'Receipt must have a cash register (currency context)',
    'receipt_supplier_or_cash_missing' => 'Receipt must have supplier and cash register',
    'quantity_exceeds_returnable' => 'Return quantity exceeds the available quantity for the receipt line',
    'cash_exceeds_refund_debt' => 'Cash refund amount exceeds the paid portion of the return',
    'receipt_has_linked_returns' => 'Cannot delete receipt: linked supplier returns exist',
    'generated_transaction_forbidden' => 'This transaction can only be created through the return document',
    'generated_transaction_locked' => 'Cannot modify auto-generated return transaction. Manage it through the return document',
    'receipt_to_pay_after_returns' => 'To pay: :amount (after returns)',
    'receipt_settled_with_returns' => 'Goods settled (return :amount)',
    'receipt_partial_with_returns' => 'Paid :paid, to pay :remaining',
];
