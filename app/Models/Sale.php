<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SalesProduct;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\CashRegister;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Project;


class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_id',
        'client_id',
        'currency_id',
        'date',
        'discount',
        'note',
        'price',
        'project_id',
        'total_price',
        'transaction_id',
        'user_id',
        'warehouse_id',
        'no_balance_update',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function products()
    {
        return $this->hasMany(SalesProduct::class, 'sale_id');
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
