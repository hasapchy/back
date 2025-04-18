<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
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

    // Связь с контактами клиента
    public function phones()
    {
        return $this->hasMany(ClientsPhone::class);
    }

    public function emails()
    {
        return $this->hasMany(ClientsEmail::class);
    }

    public function balance()
    {
        return $this->hasOne(ClientBalance::class, 'client_id');
    }
}
