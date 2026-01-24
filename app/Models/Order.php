<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Services\CacheService;

/**
 * Модель заказа
 *
 * @property int $id
 * @property string $name Название заказа
 * @property int $client_id ID клиента
 * @property int $user_id ID пользователя
 * @property int $status_id ID статуса заказа
 * @property string|null $description Описание
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $date Дата заказа
 * @property int|null $order_id ID родительского заказа
 * @property float $price Цена заказа
 * @property float $discount Скидка
 * @property int|null $cash_id ID кассы
 * @property int|null $warehouse_id ID склада
 * @property int|null $project_id ID проекта
 * @property int|null $category_id ID категории
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $user
 * @property-read \App\Models\OrderStatus $status
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\OrderProduct[] $orderProducts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\OrderProduct[] $products
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\OrderTempProduct[] $tempProducts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Comment[] $comments
 * @property-read \App\Models\Warehouse|null $warehouse
 * @property-read \App\Models\CashRegister|null $cash
 * @property-read \App\Models\Project|null $project
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Category|null $category
 */
class Order extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'client_id',
        'user_id',
        'status_id',
        'description',
        'note',
        'date',
        'price',
        'discount',
        'cash_id',
        'warehouse_id',
        'project_id',
        'category_id',
    ];


    protected static $logAttributes = [
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
            CacheService::invalidateTransactionsCache();
            if ($order->client_id) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($order->client_id);
            }
            CacheService::invalidateCashRegistersCache();
            if ($order->project_id) {
                CacheService::invalidateProjectCache($order->project_id);
            }
        });
    }

    protected $casts = [
        'price' => 'decimal:5',
        'discount' => 'decimal:5',
        'client_id' => 'integer',
        'project_id' => 'integer',
    ];

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Связь со статусом заказа
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    /**
     * Связь с продуктами заказа
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    /**
     * Связь с продуктами заказа (алиас)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    /**
     * Связь с временными продуктами заказа
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tempProducts()
    {
        return $this->hasMany(OrderTempProduct::class, 'order_id');
    }

    /**
     * Morphable связь с транзакциями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    /**
     * Связь с комментариями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Связь со складом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Связь с кассой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    /**
     * Алиас для обратной совместимости
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     * @deprecated Используйте cashRegister() вместо cash()
     */
    public function cash()
    {
        return $this->cashRegister();
    }

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Связь с активностями (activity log)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Связь с категорией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Scope для фильтрации по компании
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $companyId ID компании
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCompany($query, $companyId = null)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query;
    }

    /**
     * Scope для фильтрации по статусу
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $statusId ID статуса
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, $statusId = null)
    {
        if ($statusId) {
            return $query->where('status_id', $statusId);
        }
        return $query;
    }

    /**
     * Scope для фильтрации по дате
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $date Дата
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByDate($query, $date = null)
    {
        if ($date) {
            return $query->whereDate('date', $date);
        }
        return $query;
    }
}
