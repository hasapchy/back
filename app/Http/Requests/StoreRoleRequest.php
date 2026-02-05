<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreRoleRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
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
        $companyId = $this->header('X-Company-ID');

        $uniqueRule = Rule::unique('roles', 'name')
            ->connection('central')
            ->where('guard_name', 'api');

        if ($companyId) {
            $uniqueRule->where('company_id', $companyId);
        } else {
            $uniqueRule->whereNull('company_id');
        }

        return [
            'name' => ['required', 'string', 'max:255', $uniqueRule],
            'permissions' => 'nullable|array|max:1000',
            'permissions.*' => 'string|exists:central.permissions,name,guard_name,api',
        ];
    }

    /**
     * Подготовить данные для валидации
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    /**
     * Обработать неудачную валидацию
     *
     * @param Validator $validator
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
