<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreClientRequest extends FormRequest
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
            'first_name'       => 'required|string',
            'is_conflict'      => 'sometimes|nullable|boolean',
            'is_supplier'      => 'sometimes|nullable|boolean',
            'last_name'        => 'nullable|string',
            'patronymic'       => 'nullable|string',
            'contact_person'   => 'nullable|string',
            'position'         => 'nullable|string',
            'client_type'      => 'required|string|in:company,individual,employee,investor',
            'employee_id'      => 'nullable|exists:users,id',
            'address'          => 'nullable|string',
            'phones'           => 'required|array',
            'phones.*'         => 'string|distinct|min:6',
            'emails'           => 'sometimes|nullable',
            'emails.*'         => 'nullable|email|distinct',
            'note'             => 'nullable|string',
            'status'           => 'nullable|boolean',
            'discount'         => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percent',
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

        // Нормализация boolean полей
        if (isset($data['is_supplier'])) {
            $data['is_supplier'] = filter_var($data['is_supplier'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['is_conflict'])) {
            $data['is_conflict'] = filter_var($data['is_conflict'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['status'])) {
            $data['status'] = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN);
        }

        // Нормализация пустых строк в null
        $nullableFields = ['last_name', 'patronymic', 'contact_person', 'position', 'address', 'note', 'discount_type'];
        foreach ($nullableFields as $field) {
            if (isset($data[$field]) && ($data[$field] === '' || (is_string($data[$field]) && trim($data[$field]) === ''))) {
                $data[$field] = null;
            }
        }

        // Нормализация discount
        if (isset($data['discount']) && $data['discount'] === '') {
            $data['discount'] = null;
        }

        // Нормализация phones - убедиться, что это массив
        if (isset($data['phones']) && is_string($data['phones'])) {
            $data['phones'] = array_filter(explode(',', $data['phones']), function ($phone) {
                return trim($phone) !== '';
            });
        }

        // Нормализация emails - убедиться, что это массив
        if (isset($data['emails']) && is_string($data['emails'])) {
            $data['emails'] = array_filter(explode(',', $data['emails']), function ($email) {
                return trim($email) !== '';
            });
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
