<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class OrderTransaction extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_id',
        'transaction_id',
    ];

    protected static $logAttributes = [
        'order_id',
        'transaction_id',
    ];

    protected static $logName = 'order_transaction';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        switch ($eventName) {
            case 'created':
                $transaction = $this->transaction;
                if ($transaction) {
                    $amount = number_format($transaction->amount, 2);
                    $currency = $transaction->currency ? $transaction->currency->code : '₽';
                    return "Добавлена транзакция #{$transaction->id} ({$amount} {$currency})";
                }
                return "Добавлена транзакция к заказу";
            case 'updated':
                return 'Связь заказ-транзакция обновлена';
            case 'deleted':
                $transaction = $this->transaction;
                if ($transaction) {
                    $amount = number_format($transaction->amount, 2);
                    $currency = $transaction->currency ? $transaction->currency->code : '₽';
                    return "Удалена транзакция #{$transaction->id} ({$amount} {$currency}) из заказа";
                }
                return "Удалена транзакция из заказа";
            default:
                return "Связь заказ-транзакция была {$eventName}";
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName('order_transaction')
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    public function comments()
    {
        return $this->morphMany(\App\Models\Comment::class, 'commentable');
    }
}
