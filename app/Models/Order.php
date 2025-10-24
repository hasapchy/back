<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Order extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'client_id',
        'user_id',
        'status_id',
        'description',
        'note',
        'date',
        'order_id',
        'price',
        'discount',
        'cash_id',
        'warehouse_id',
        'project_id',
        'category_id',
    ];


    protected static $logAttributes = [
        'name',
        'client_id',
        'user_id',
        'status_id',
        'description',
        'note',
        'date',
        'order_id',
        'price',
        'discount',
        'cash_id',
        'warehouse_id',
        'project_id',
        'category_id'
    ];

    protected static $logName = 'order';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        switch ($eventName) {
            case 'created':
                return 'Создан заказ';
            case 'updated':
                return 'Заказ обновлен';
            case 'deleted':
                return 'Заказ удален';
            default:
                return "Заказ был {$eventName}";
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName('order')
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected static function booted()
    {
        static::deleting(function ($order) {
            \Illuminate\Support\Facades\Log::info('Order::deleting - START', [
                'order_id' => $order->id,
                'client_id' => $order->client_id
            ]);

            // Удаляем все связанные транзакции
            $transactionsCount = $order->transactions()->count();
            $order->transactions()->delete();

            \Illuminate\Support\Facades\Log::info('Order::deleting - Transactions deleted', [
                'order_id' => $order->id,
                'transactions_deleted' => $transactionsCount
            ]);

            // Инвалидируем кэши
            \App\Services\CacheService::invalidateTransactionsCache();
            if ($order->client_id) {
                \App\Services\CacheService::invalidateClientsCache();
                \App\Services\CacheService::invalidateClientBalanceCache($order->client_id);
            }
            \App\Services\CacheService::invalidateCashRegistersCache();
            if ($order->project_id) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($order->project_id);
            }

            \Illuminate\Support\Facades\Log::info('Order::deleting - COMPLETED', [
                'order_id' => $order->id
            ]);
        });
    }

    protected $casts = [
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function status()
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }



    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    public function products()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    public function tempProducts()
    {
        return $this->hasMany(OrderTempProduct::class, 'order_id');
    }
    // Morphable связь с транзакциями (новая архитектура)
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function cash()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function additionalFieldValues()
    {
        return $this->hasMany(OrderAfValue::class);
    }

    public function getAdditionalFieldValues()
    {
        return $this->additionalFieldValues()
            ->with('additionalField')
            ->get()
            ->map(function ($value) {
                return [
                    'field' => $value->additionalField,
                    'value' => $value->value,
                    'formatted_value' => $value->getFormattedValue()
                ];
            });
    }


    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
