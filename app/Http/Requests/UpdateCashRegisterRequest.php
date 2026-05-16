<?php

namespace App\Http\Requests;

use App\Models\CashRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateCashRegisterRequest extends FormRequest
{
    /**
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('color') && $this->input('color') === '') {
            $this->merge(['color' => null]);
        }
    }

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $cashRegister = CashRegister::query()->find($this->route('id'));

        if (! $cashRegister) {
            return true;
        }

        return $this->user()->can('update', $cashRegister);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'nullable|string',
            'users' => 'required|array|min:1',
            'users.*' => 'exists:users,id',
            'is_cash' => 'nullable|boolean',
            'is_working_minus' => 'nullable|boolean',
            'icon' => ['nullable', 'string', 'max:100', Rule::in($this->allowedIcons())],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    private function allowedIcons(): array
    {
        return CashRegister::ALLOWED_ICONS;
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
