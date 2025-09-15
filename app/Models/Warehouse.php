<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\WarehouseStock;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'company_id'];

    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class, 'warehouse_id');
    }

    public function whUsers()
    {
        return $this->hasMany(WhUser::class, 'warehouse_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'wh_users', 'warehouse_id', 'user_id');
    }

    public function getUsersAttribute()
    {
        return $this->users()->get();
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
