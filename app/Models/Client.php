<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\ClientsPhone;
use App\Models\ClientsEmail;

/**
 * Модель клиента
 *
 * @property int $id
 * @property int|null $user_id ID пользователя, создавшего клиента
 * @property int|null $company_id ID компании
 * @property int|null $employee_id ID сотрудника (для типов employee/investor)
 * @property string $client_type Тип клиента (company, individual, employee, investor)
 * @property bool $is_supplier Является ли поставщиком
 * @property bool $is_conflict Есть ли конфликт
 * @property string $first_name Имя
 * @property string|null $last_name Фамилия
 * @property string|null $contact_person Контактное лицо
 * @property string|null $address Адрес
 * @property string|null $note Примечание
 * @property string|null $discount_type Тип скидки (fixed, percent)
 * @property float|null $discount Размер скидки
 * @property bool $status Статус активности
 * @property int|null $sort Порядок сортировки
 * @property float $balance Баланс клиента
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\User|null $employee
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ClientsPhone[] $phones
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ClientsEmail[] $emails
 */
class Client extends Model
{
    use HasFactory;

    public const CLIENT_TYPES = ['company', 'individual', 'employee', 'investor'];

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

    /**
     * Связь с пользователем (кто создал клиента)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Связь с сотрудником (для типов employee/investor)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
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

    /**
     * Связь с телефонами клиента
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function phones()
    {
        return $this->hasMany(ClientsPhone::class, 'client_id');
    }

    /**
     * Связь с email клиента
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function emails()
    {
        return $this->hasMany(ClientsEmail::class, 'client_id');
    }
}
