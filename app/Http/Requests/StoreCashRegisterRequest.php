<?php

namespace App\Http\Requests;

use App\Models\CashRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCashRegisterRequest extends FormRequest
{
    /**
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('color') && $this->input('color') === '') {
            $data['color'] = null;
        }

        if (! $this->has('sort_order')) {
            $data['sort_order'] = 0;
        }

        if (! $this->has('icon_size')) {
            $data['icon_size'] = CashRegister::DEFAULT_ICON_SIZE;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', CashRegister::class);
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
            'balance' => 'required|numeric',
            'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array|min:1',
            'users.*' => 'exists:users,id',
            'is_cash' => 'nullable|boolean',
            'is_working_minus' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'icon' => ['nullable', 'string', 'max:100', Rule::in($this->allowedIcons())],
            'icon_size' => ['nullable', 'string', Rule::in($this->allowedIconSizes())],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    private function allowedIcons(): array
    {
        return CashRegister::ALLOWED_ICONS;
    }

    private function allowedIconSizes(): array
    {
        return CashRegister::ALLOWED_ICON_SIZES;
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
