<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\ClientsPhone;
use App\Models\ClientsEmail;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'employee_id',
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
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    // Связь с пользователем (кто создал клиента)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Связь с сотрудником (для типов employee/investor)
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    // Связь с компанией
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
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

    // Column `balance` now stored directly on clients table
}
