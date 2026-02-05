<?php

namespace App\Models;

use App\Eloquent\Relations\BelongsToManyAcrossConnections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\WarehouseStock;
use App\Models\Traits\HasManyToManyUsers;

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
    use HasFactory, HasManyToManyUsers;

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
     * Связь many-to-many с пользователями (pivot wh_users в tenant, users в central)
     *
     * @return BelongsToManyAcrossConnections
     */
    public function users()
    {
        return new BelongsToManyAcrossConnections(
            (new User)->newQuery(),
            $this,
            'wh_users',
            'warehouse_id',
            'user_id'
        );
    }

    /**
     * Проверить, есть ли у склада пользователь (pivot в tenant)
     */
    public function hasUser($userId): bool
    {
        return $this->whUsers()->where('user_id', $userId)->exists();
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
