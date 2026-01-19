<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'is_recurring' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название праздника обязательно',
            'date.required' => 'Дата праздника обязательна',
            'date.date' => 'Некорректный формат даты',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated();

        // Добавляем company_id из заголовка
        $validated['company_id'] = request()->header('X-Company-ID');

        // Устанавливаем значения по умолчанию
        $validated['is_recurring'] = $validated['is_recurring'] ?? true;
        $validated['color'] = $validated['color'] ?? '#FF5733';

        return $validated;
    }
}
