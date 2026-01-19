<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'date' => 'sometimes|required|date',
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
}
