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
        'user_ids', 
    ];

    protected $casts = [
        'user_ids' => 'array',
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

    public function financialTransactions()
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    public function templates()
    {
        return $this->hasMany(Template::class);
    }

    public function permittedUsers()
    {
        return User::whereIn('id', $this->user_ids ?? [])->get();
    }

}
