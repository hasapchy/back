<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\ClientsPhone;
use App\Models\ClientsEmail;
use App\Models\ClientBalance;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_type',
        'is_supplier',
        'is_conflict',
        'first_name',
        'last_name',
        'contact_person',
        'address',
        'note',
        'discount_type',
        'discount',
        'status',
        'sort',
    ];

    // Связь с пользователем
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Связь с контактами клиента
    public function phones()
    {
        return $this->hasMany(ClientsPhone::class, 'client_id');
    }

    public function emails()
    {
        return $this->hasMany(ClientsEmail::class, 'client_id');
    }

    public function balance()
    {
        return $this->hasOne(ClientBalance::class, 'client_id');
    }

    // Геттер для получения баланса
    public function getBalanceAttribute()
    {
        return $this->balance()->first()?->balance ?? 0;
    }
}
