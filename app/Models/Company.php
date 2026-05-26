<?php

namespace App\Models;

use App\Casts\WorkScheduleCast;
use App\Support\DefaultWorkSchedule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель компании
 *
 * @property int $id
 * @property string $name Название компании
 * @property string|null $full_name Полное название компании (для счёта)
 * @property string|null $address Адрес компании
 * @property string|null $phone Телефон компании
 * @property string|null $registration_number Регистрационный номер
 * @property string|null $email Email компании
 * @property string|null $warehouse_number Номер склада (W/H)
 * @property string $logo Логотип компании
 * @property bool $show_deleted_transactions Показывать ли удаленные транзакции
 * @property int $rounding_decimals Количество знаков после запятой для округления
 * @property bool $rounding_enabled Включено ли округление
 * @property string $rounding_direction Направление округления
 * @property float|null $rounding_custom_threshold Порог для кастомного округления
 * @property bool $rounding_orders_enabled Округление сумм в заказах
 * @property bool $rounding_contracts_enabled Округление сумм в контрактах
 * @property bool $rounding_warehouse_enabled Округление сумм на складе
 * @property int $rounding_quantity_decimals Количество знаков после запятой для округления количества
 * @property bool $rounding_quantity_enabled Включено ли округление количества
 * @property string $rounding_quantity_direction Направление округления количества
 * @property float|null $rounding_quantity_custom_threshold Порог для кастомного округления количества
 * @property bool $skip_project_order_balance Пропускать ли обновление баланса для заказов проекта
 * @property array|null $work_schedule Рабочий график (сырое значение из БД)
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
        'full_name',
        'address',
        'phone',
        'registration_number',
        'email',
        'warehouse_number',
        'logo',
        'show_deleted_transactions',
        'rounding_decimals',
        'rounding_enabled',
        'rounding_direction',
        'rounding_custom_threshold',
        'rounding_orders_enabled',
        'rounding_contracts_enabled',
        'rounding_warehouse_enabled',
        'rounding_quantity_decimals',
        'rounding_quantity_enabled',
        'rounding_quantity_direction',
        'rounding_quantity_custom_threshold',
        'skip_project_order_balance',
        'work_schedule',
    ];

    protected $attributes = [
        'logo' => 'logo.png',
        'show_deleted_transactions' => false,
        'rounding_decimals' => 2,
        'rounding_enabled' => true,
        'rounding_direction' => 'standard',
        'rounding_orders_enabled' => true,
        'rounding_contracts_enabled' => false,
        'rounding_warehouse_enabled' => true,
        'rounding_quantity_decimals' => 2,
        'rounding_quantity_enabled' => true,
        'rounding_quantity_direction' => 'standard',
        'skip_project_order_balance' => true,
    ];

    protected $casts = [
        'show_deleted_transactions' => 'boolean',
        'rounding_enabled' => 'boolean',
        'rounding_orders_enabled' => 'boolean',
        'rounding_contracts_enabled' => 'boolean',
        'rounding_warehouse_enabled' => 'boolean',
        'rounding_quantity_enabled' => 'boolean',
        'skip_project_order_balance' => 'boolean',
        'work_schedule' => WorkScheduleCast::class,
    ];

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user', 'company_id', 'user_id');
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function holidays()
    {
        return $this->hasMany(CompanyHoliday::class);
    }

    public function productionCalendarDays()
    {
        return $this->hasMany(CompanyProductionCalendarDay::class);
    }

    /**
     * @return array<int, array{enabled: bool, start: string, end: string}>
     */
    public function effectiveWorkSchedule(): array
    {
        return $this->work_schedule ?? DefaultWorkSchedule::get();
    }
}
