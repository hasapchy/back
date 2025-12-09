<?php

namespace App\Http\Requests;

use App\Rules\ProjectAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreProjectContractRequest extends FormRequest
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
            'project_id' => ['required', new ProjectAccessRule()],
            'number' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'currency_id' => 'nullable|exists:currencies,id',
            'date' => 'required|date',
            'returned' => 'nullable|boolean',
            'files' => 'nullable|array',
            'note' => 'nullable|string',
        ];
    }

    /**
     * Подготовить данные для валидации
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['returned'])) {
            $data['returned'] = filter_var($data['returned'], FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($data);
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
