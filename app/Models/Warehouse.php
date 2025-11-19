<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\WarehouseStock;

/**
 * Модель склада
 *
 * @property int $id
 * @property string $name Название склада
 * @property int|null $company_id ID компании
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WarehouseStock[] $stocks
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhUser[] $whUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \App\Models\Company|null $company
 */
class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'company_id'];

    /**
     * Связь со складскими остатками
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class, 'warehouse_id');
    }

    /**
     * Связь с пользователями склада через промежуточную таблицу
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function whUsers()
    {
        return $this->hasMany(WhUser::class, 'warehouse_id');
    }

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'wh_users', 'warehouse_id', 'user_id');
    }

    /**
     * Accessor для получения пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersAttribute()
    {
        $relation = $this->getRelationValue('users');

        if ($relation !== null) {
            return $relation;
        }

        return $this->users()->get();
    }

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
