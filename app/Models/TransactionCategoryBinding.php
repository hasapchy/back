<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class TransactionCategoryBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'binding_key',
        'transaction_category_id',
    ];

    public function getTable()
    {
        if (Schema::hasTable('transaction_category_bindings')) {
            return 'transaction_category_bindings';
        }

        return 'company_transaction_category_bindings';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function transactionCategory()
    {
        return $this->belongsTo(TransactionCategory::class);
    }
}
