<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegisterUser extends Model
{
    use HasFactory;

    protected $table = 'cash_register_users';

    protected $fillable = ['cash_register_id', 'user_id'];

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
