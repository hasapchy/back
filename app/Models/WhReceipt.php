<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\CacheService;

class WhReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'note',
        'cash_id',
        'amount',
        'date',
        'user_id',
        'project_id',
    ];

    protected $casts = [
        'amount' => 'decimal:5',
    ];

    protected static function booted()
    {
        static::deleting(function ($receipt) {
            \Illuminate\Support\Facades\Log::info('WhReceipt::deleting - START', [
                'receipt_id' => $receipt->id,
                'supplier_id' => $receipt->supplier_id
            ]);

            // Удаляем все связанные транзакции (по одной, чтобы сработали hooks)
            $transactions = $receipt->transactions()->get();
            $transactionsCount = $transactions->count();
            foreach ($transactions as $transaction) {
                try {
                    $transaction->delete(); // Вызываем delete() для каждой транзакции отдельно
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('WhReceipt::deleting - Error deleting transaction', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage()
                    ]);
                    throw $e; // Пробрасываем исключение дальше
                }
            }

            \Illuminate\Support\Facades\Log::info('WhReceipt::deleting - Transactions deleted', [
                'receipt_id' => $receipt->id,
                'transactions_deleted' => $transactionsCount
            ]);

            // Инвалидируем кэши
            CacheService::invalidateTransactionsCache();
            if ($receipt->supplier_id) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($receipt->supplier_id);
            }
            CacheService::invalidateCashRegistersCache();
            if ($receipt->project_id) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($receipt->project_id);
            }

            \Illuminate\Support\Facades\Log::info('WhReceipt::deleting - COMPLETED', [
                'receipt_id' => $receipt->id
            ]);
        });
    }

    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function products()
    {
        return $this->hasMany(WhReceiptProduct::class, 'receipt_id');
    }

    public function productsPivot()
    {
        return $this->belongsToMany(
            Product::class,
            'wh_receipt_products',
            'receipt_id',
            'product_id'
        )->withPivot('quantity');
    }

    // Morphable связь с транзакциями (новая архитектура)
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
