<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'user_id',
    ];

    protected $casts = [
        'type' => 'boolean',
    ];

    // Системные категории, которые нельзя удалять
    protected static $protectedCategories = [
        'Перемещение',
        'Выплата зарплаты',
        'Продажа',
        'Предоплата',
        'Оплата покупателя за услугу, товар',
        'Прочий приход денег',
        'Возврат денег покупателю',
        'Оплата поставщикам товаров, запчастей',
        'Прочий расход денег'
    ];

    // Отношение к пользователю
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Отношение к транзакциям
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    // Проверка, можно ли удалить категорию
    public function canBeDeleted()
    {
        return !in_array($this->name, self::$protectedCategories);
    }

    // Проверка, можно ли редактировать категорию
    public function canBeEdited()
    {
        return !in_array($this->name, self::$protectedCategories);
    }

    // Переопределяем метод delete для защиты системных категорий
    public function delete()
    {
        if (!$this->canBeDeleted()) {
            throw new \Exception('Нельзя удалить системную категорию: ' . $this->name);
        }

        return parent::delete();
    }
}
