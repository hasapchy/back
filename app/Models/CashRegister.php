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
        'currency_id',
        'company_id',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
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
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function Transactions()
    {
        return $this->hasMany(Transaction::class, 'cash_id');
    }

    public function templates()
    {
        return $this->hasMany(Template::class);
    }

    public function cashRegisterUsers()
    {
        return $this->hasMany(CashRegisterUser::class, 'cash_register_id');
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

    // roundAmount удален — логика округления теперь на уровне компании
}
