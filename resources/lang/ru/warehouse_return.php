<?php

return [
    'receipt_linked_to_purchase' => 'Нельзя оформить возврат по оприходованию из закупки',
    'receipt_status_not_eligible' => 'Возврат доступен только для подтверждённых или завершённых оприходований',
    'receipt_supplier_required' => 'У оприходования должен быть указан поставщик',
    'receipt_cash_required' => 'У оприходования должна быть указана касса (валютный контекст)',
    'receipt_supplier_or_cash_missing' => 'У оприходования должны быть указаны поставщик и касса',
    'quantity_exceeds_returnable' => 'Количество возврата превышает доступное по строке оприходования',
    'cash_exceeds_refund_debt' => 'Сумма кассовых возвратов превышает оплаченную часть возврата',
    'receipt_has_linked_returns' => 'Нельзя удалить оприходование: есть связанные возвраты поставщику',
    'generated_transaction_forbidden' => 'Эту проводку можно создать только через документ возврата',
    'generated_transaction_locked' => 'Нельзя изменить автоматическую проводку возврата. Управляйте ей через документ возврата',
    'receipt_to_pay_after_returns' => 'К оплате: :amount (с учётом возвратов)',
    'receipt_settled_with_returns' => 'Закрыто по товару (возврат :amount)',
    'receipt_partial_with_returns' => 'Оплачено :paid, к оплате :remaining',
];
