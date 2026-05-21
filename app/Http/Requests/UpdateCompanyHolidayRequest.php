<?php

namespace App\Http\Requests;

use App\Models\CompanyHoliday;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateCompanyHolidayRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => 'sometimes|integer|exists:companies,id',
            'name' => 'nullable|string|max:255',
            'date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'is_recurring' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
            'icon' => ['required', 'string', 'max:100', Rule::in($this->allowedIcons())],
        ];
    }

    /**
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('icon') && $this->input('icon') === '') {
            $this->merge(['icon' => null]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function allowedIcons(): array
    {
        return CompanyHoliday::ALLOWED_ICONS;
    }

    /**
     * Обработать неудачную валидацию
     *
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
