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
        'work_schedule',
    ];

    protected $attributes = [
        'logo' => 'logo.png',
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
        'work_schedule' => 'array',
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

     /**
     * Получить рабочий график с дефолтными значениями
     * Работает с raw значением из БД, так как cast 'array' применяется после accessor
     */
    public function getWorkScheduleAttribute($value)
    {
        // Получаем raw значение из БД (до применения cast)
        $rawValue = $this->getAttributes()['work_schedule'] ?? null;

        if ($rawValue) {
            // Если это уже массив (после cast), возвращаем как есть
            if (is_array($rawValue)) {
                return $rawValue;
            }
            // Если это JSON строка, декодируем
            if (is_string($rawValue)) {
                $decoded = json_decode($rawValue, true);
                if ($decoded) {
                    return $decoded;
                }
            }
        }

        // Дефолтный график, если не установлен
        return $this->getDefaultWorkSchedule();
    }

    /**
     * Получить дефолтный рабочий график
     */
    protected function getDefaultWorkSchedule()
    {
        return [
            1 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Monday
            2 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Tuesday
            3 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Wednesday
            4 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Thursday
            5 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Friday
            6 => ['enabled' => false, 'start' => '10:00', 'end' => '14:00'], // Saturday
            7 => ['enabled' => false, 'start' => '00:00', 'end' => '00:00']  // Sunday
        ];
    }
}
