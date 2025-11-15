<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель компании
 *
 * @property int $id
 * @property string $name Название компании
 * @property string $logo Логотип компании
 * @property bool $show_deleted_transactions Показывать ли удаленные транзакции
 * @property int $rounding_decimals Количество знаков после запятой для округления
 * @property bool $rounding_enabled Включено ли округление
 * @property string $rounding_direction Направление округления
 * @property float|null $rounding_custom_threshold Порог для кастомного округления
 * @property int $rounding_quantity_decimals Количество знаков после запятой для округления количества
 * @property bool $rounding_quantity_enabled Включено ли округление количества
 * @property string $rounding_quantity_direction Направление округления количества
 * @property float|null $rounding_quantity_custom_threshold Порог для кастомного округления количества
 * @property bool $skip_project_order_balance Пропускать ли обновление баланса для заказов проекта
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'show_deleted_transactions',
        'rounding_decimals',
        'rounding_enabled',
        'rounding_direction',
        'rounding_custom_threshold',
        'rounding_quantity_decimals',
        'rounding_quantity_enabled',
        'rounding_quantity_direction',
        'rounding_quantity_custom_threshold',
        'skip_project_order_balance',
    ];

    protected $attributes = [
        'logo' => 'logo.jpg',
        'show_deleted_transactions' => false,
        'rounding_enabled' => true,
        'rounding_direction' => 'standard',
        'rounding_quantity_enabled' => true,
        'rounding_quantity_direction' => 'standard',
        'skip_project_order_balance' => true,
    ];

    protected $casts = [
        'show_deleted_transactions' => 'boolean',
        'rounding_enabled' => 'boolean',
        'rounding_quantity_enabled' => 'boolean',
        'skip_project_order_balance' => 'boolean',
    ];

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user');
    }
}
