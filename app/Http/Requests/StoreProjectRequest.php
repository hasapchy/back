<?php

namespace App\Http\Requests;

use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreProjectRequest extends FormRequest
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
        $rules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => ['required', new ClientAccessRule()],
            'users' => 'nullable|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        if ($this->has('budget') || $this->has('currency_id')) {
            $rules['budget'] = 'required|numeric';
            $rules['currency_id'] = 'nullable|exists:currencies,id';
        }

        return $rules;
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

