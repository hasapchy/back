<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Модель шаблонов сообщений
 *
 * @property int $id
 * @property string $type Тип шаблона (birthday, anniversary, etc.)
 * @property string $name Название шаблона
 * @property string $content HTML контент с переменными
 * @property int|null $company_id ID компании
 * @property int $creator_id ID создателя шаблона
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\User $creator
 */
class MessageTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'content',
        'company_id',
        'creator_id',
        'is_active',
    ];

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Заменяет переменные в шаблоне на реальные значения
     *
     * @param  array<string, string>  $variables  Массив переменных ['name' => 'Иван', 'surname' => 'Иванов']
     */
    public function render(array $variables): string
    {
        $content = $this->content;
        foreach ($variables as $key => $value) {
            $content = str_replace('{{'.$key.'}}', $value ?? '', $content);
        }

        return $content;
    }
}
