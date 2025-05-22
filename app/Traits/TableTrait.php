<?php

namespace App\Traits;

use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;

trait TableTrait
{
    use WithPagination;

    public $perPage = 10; // Количество записей на странице
    public $search = ''; // Поиск
    public $dateFilter = 'today'; // Фильтр по дате
    public $customDateRange = ['start' => null, 'end' => null]; // Кастомный диапазон дат
    public $columns; // Список колонок
    public $order; // Порядок колонок
    public $visibility; // Видимость колонок
    public $tableName; // Уникальное имя таблицы

    // Инициализация трейта
    public function mountTableTrait($tableName, $columns)
    {
        $this->tableName = $tableName;
        $this->columns = collect($columns); // Преобразуем в коллекцию для удобства
        $this->loadTableSettings(); // Загружаем настройки пользователя
    }

    // Загрузка настроек колонок (порядок и видимость)
    public function loadTableSettings()
    {
        $settings = auth()->user()->tableSettings()
            ->where('table_name', $this->tableName)
            ->first();

        if ($settings) {
            $this->order = $settings->order;
            $this->visibility = $settings->visibility;
        } else {
            $this->order = array_column($this->columns->toArray(), 'key');
            $this->visibility = array_fill_keys($this->order, true);
        }
    }

    // Сохранение настроек колонок
    public function updateTableSettings($order, $visibility)
    {
        auth()->user()->tableSettings()->updateOrCreate(
            ['table_name' => $this->tableName],
            [
                'order' => $order,
                'visibility' => $visibility,
            ]
        );
        $this->order = $order;
        $this->visibility = $visibility;
    }

    // Пример метода для фильтрации по дате (будет переопределяться в компоненте)
    public function applyFilters($query)
    {
        if ($this->search) {
            $query->where('some_field', 'like', '%' . $this->search . '%');
        }
        if ($this->dateFilter === 'custom' && $this->customDateRange['start']) {
            $query->whereBetween('date', [
                $this->customDateRange['start'],
                $this->customDateRange['end']
            ]);
        }
        return $query->paginate($this->perPage);
    }
}