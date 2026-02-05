<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCashRegisterRequest extends FormRequest
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
        return [
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array|min:1',
            'users.*' => Rule::exists(User::class, 'id'),
            'is_cash' => 'nullable|boolean',
            'icon' => ['nullable', 'string', 'max:100', Rule::in($this->allowedIcons())],
        ];
    }

    private function allowedIcons(): array
    {
        return [
            'fa-solid fa-building-columns',
            'fa-solid fa-ticket',
            'fa-solid fa-location-dot',
            'fa-solid fa-fire',
            'fa-solid fa-thumbs-up',
            'fa-solid fa-dollar-sign',
            'fa-solid fa-cash-register',
            'fa-solid fa-credit-card',
            'fa-solid fa-briefcase',
            'fa-solid fa-user',
            'fa-solid fa-star',
        ];
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
