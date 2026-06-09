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
 * @property int $display_decimals Количество знаков после запятой для отображения
 * @property bool $rounding_enabled Включено ли округление
 * @property string $rounding_direction Направление округления
 * @property float|null $rounding_custom_threshold Порог для кастомного округления
 * @property bool $rounding_orders_enabled Округление сумм в заказах
 * @property int $rounding_orders_decimals Количество знаков округления сумм в заказах
 * @property bool $rounding_contracts_enabled Округление сумм в контрактах
 * @property int $rounding_contracts_decimals Количество знаков округления сумм в контрактах
 * @property bool $rounding_warehouse_enabled Округление сумм на складе
 * @property int $rounding_warehouse_decimals Количество знаков округления сумм на складе
 * @property bool $rounding_transactions_enabled Округление сумм в транзакциях без источника
 * @property int $rounding_transactions_decimals Количество знаков округления сумм в транзакциях
 * @property int $rounding_quantity_decimals Количество знаков после запятой для округления количества
 * @property bool $rounding_quantity_enabled Включено ли округление количества
 * @property string $rounding_quantity_direction Направление округления количества
 * @property float|null $rounding_quantity_custom_threshold Порог для кастомного округления количества
 * @property bool $skip_project_order_balance Пропускать ли обновление баланса для заказов проекта
 * @property array|null $work_schedule Рабочий график (сырое значение из БД)
 * @property array|null $ui_theme Цвета интерфейса компании
 * @property array<string, int> $transaction_category_bindings Привязки категорий транзакций по ключам
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
        'display_decimals',
        'rounding_enabled',
        'rounding_direction',
        'rounding_custom_threshold',
        'rounding_orders_enabled',
        'rounding_orders_decimals',
        'rounding_contracts_enabled',
        'rounding_contracts_decimals',
        'rounding_warehouse_enabled',
        'rounding_warehouse_decimals',
        'rounding_transactions_enabled',
        'rounding_transactions_decimals',
        'rounding_quantity_decimals',
        'rounding_quantity_enabled',
        'rounding_quantity_direction',
        'rounding_quantity_custom_threshold',
        'skip_project_order_balance',
        'work_schedule',
        'ui_theme',
    ];

    protected $attributes = [
        'logo' => 'logo.png',
        'show_deleted_transactions' => false,
        'display_decimals' => 2,
        'rounding_enabled' => true,
        'rounding_direction' => 'standard',
        'rounding_orders_enabled' => true,
        'rounding_orders_decimals' => 2,
        'rounding_contracts_enabled' => false,
        'rounding_contracts_decimals' => 2,
        'rounding_warehouse_enabled' => true,
        'rounding_warehouse_decimals' => 2,
        'rounding_transactions_enabled' => true,
        'rounding_transactions_decimals' => 2,
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
        'rounding_transactions_enabled' => 'boolean',
        'rounding_quantity_enabled' => 'boolean',
        'skip_project_order_balance' => 'boolean',
        'work_schedule' => WorkScheduleCast::class,
        'ui_theme' => 'array',
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
        return $this->hasMany(Holiday::class);
    }

    public function productionCalendarDays()
    {
        return $this->hasMany(ProductionCalendarDay::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactionCategoryBindings()
    {
        return $this->hasMany(TransactionCategoryBinding::class);
    }

    /**
     * @return array<string, int>
     */
    public function getTransactionCategoryBindingsAttribute(): array
    {
        $bindings = $this->relationLoaded('transactionCategoryBindings')
            ? $this->getRelationValue('transactionCategoryBindings')
            : $this->transactionCategoryBindings()->get(['binding_key', 'transaction_category_id']);

        return $bindings
            ->pluck('transaction_category_id', 'binding_key')
            ->map(fn ($value) => (int) $value)
            ->toArray();
    }

    /**
     * @return array<int, array{enabled: bool, start: string, end: string}>
     */
    public function effectiveWorkSchedule(): array
    {
        return $this->work_schedule ?? DefaultWorkSchedule::get();
    }
}
