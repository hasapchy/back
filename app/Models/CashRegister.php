<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Template;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'balance',
        'is_rounding',
        'currency_id',
    ];

    public static function boot()
    {
        parent::boot();

        static::deleting(function ($cashRegister) {
            if ($cashRegister->id === 1) {
                return false;
            }
        });
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function Transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function templates()
    {
        return $this->hasMany(Template::class);
    }

    public function cashRegisterUsers()
    {
        return $this->hasMany(CashRegisterUser::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'cash_register_users', 'cash_register_id', 'user_id');
    }

    public function getUsersAttribute()
    {
        return $this->users()->get();
    }

    public function hasUser($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    public function permittedUsers()
    {
        return $this->users()->get();
    }

    /**
     * Округляет сумму до целых чисел если включено округление
     */
    public function roundAmount($amount)
    {
        if ($this->is_rounding) {
            return round($amount);
        }
        return $amount;
    }
}
