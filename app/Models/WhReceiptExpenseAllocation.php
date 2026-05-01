<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $receipt_id
 * @property int $transaction_id
 * @property int $wh_receipt_product_id
 * @property string $amount_default
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read WhReceipt $receipt
 * @property-read Transaction $transaction
 * @property-read WhReceiptProduct $receiptProduct
 */
class WhReceiptExpenseAllocation extends Model
{
    protected $fillable = [
        'receipt_id',
        'transaction_id',
        'wh_receipt_product_id',
        'amount_default',
    ];

    protected $casts = [
        'amount_default' => 'decimal:5',
    ];

    /**
     * @return BelongsTo<WhReceipt, WhReceiptExpenseAllocation>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WhReceipt::class, 'receipt_id');
    }

    /**
     * @return BelongsTo<Transaction, WhReceiptExpenseAllocation>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * @return BelongsTo<WhReceiptProduct, WhReceiptExpenseAllocation>
     */
    public function receiptProduct(): BelongsTo
    {
        return $this->belongsTo(WhReceiptProduct::class, 'wh_receipt_product_id');
    }
}
