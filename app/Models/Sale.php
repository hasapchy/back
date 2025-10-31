<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SalesProduct;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\CashRegister;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Project;


class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_id',
        'client_id',
        'date',
        'discount',
        'note',
        'price',
        'project_id',
        'user_id',
        'warehouse_id',
        'no_balance_update',
    ];

    protected $casts = [
        'price' => 'decimal:5',
        'discount' => 'decimal:5',
    ];

    protected static function booted()
    {
        static::deleting(function ($sale) {
            \Illuminate\Support\Facades\Log::info('Sale::deleting - START', [
                'sale_id' => $sale->id,
                'client_id' => $sale->client_id
            ]);

            // Удаляем все связанные транзакции (по одной, чтобы сработали hooks)
            $transactions = $sale->transactions()->get();
            $transactionsCount = $transactions->count();
            foreach ($transactions as $transaction) {
                $transaction->delete(); // Вызываем delete() для каждой транзакции отдельно
            }

            \Illuminate\Support\Facades\Log::info('Sale::deleting - Transactions deleted', [
                'sale_id' => $sale->id,
                'transactions_deleted' => $transactionsCount
            ]);

            // Инвалидируем кэши
            \App\Services\CacheService::invalidateTransactionsCache();
            if ($sale->client_id) {
                \App\Services\CacheService::invalidateClientsCache();
                \App\Services\CacheService::invalidateClientBalanceCache($sale->client_id);
            }
            \App\Services\CacheService::invalidateCashRegistersCache();
            if ($sale->project_id) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($sale->project_id);
            }

            \Illuminate\Support\Facades\Log::info('Sale::deleting - COMPLETED', [
                'sale_id' => $sale->id
            ]);
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function products()
    {
        return $this->hasMany(SalesProduct::class, 'sale_id');
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    // Старая связь удалена, теперь используется morphable связь transactions()

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    // Morphable связь с транзакциями
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}
