<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

trait ValidatesClientFields
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    protected function clientFieldRules(): array
    {
        return [
            'first_name' => 'required|string',
            'is_conflict' => 'sometimes|nullable|boolean',
            'is_supplier' => 'sometimes|nullable|boolean',
            'last_name' => 'nullable|string',
            'patronymic' => 'nullable|string',
            'position' => 'nullable|string',
            'client_type' => 'required|string|in:company,individual,employee,investor',
            'employee_id' => 'nullable|exists:users,id',
            'address' => 'nullable|string',
            'phones' => 'required|array',
            'phones.*' => 'string|distinct|min:6',
            'emails' => 'sometimes|nullable',
            'emails.*' => 'nullable|email|distinct',
            'note' => 'nullable|string',
            'status' => 'nullable|boolean',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'required|in:fixed,percent',
        ];
    }

    /**
     * @return void
     */
    protected function prepareClientFieldsForValidation(): void
    {
        $data = $this->all();

        if (isset($data['is_supplier'])) {
            $data['is_supplier'] = filter_var($data['is_supplier'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['is_conflict'])) {
            $data['is_conflict'] = filter_var($data['is_conflict'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['status'])) {
            $data['status'] = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN);
        }

        $nullableFields = ['last_name', 'patronymic', 'position', 'address', 'note'];
        foreach ($nullableFields as $field) {
            if (isset($data[$field]) && ($data[$field] === '' || (is_string($data[$field]) && trim($data[$field]) === ''))) {
                $data[$field] = null;
            }
        }

        if (isset($data['discount']) && $data['discount'] === '') {
            $data['discount'] = null;
        }

        if (isset($data['phones']) && is_string($data['phones'])) {
            $data['phones'] = array_filter(explode(',', $data['phones']), function ($phone) {
                return trim($phone) !== '';
            });
        }

        if (isset($data['emails']) && is_string($data['emails'])) {
            $data['emails'] = array_filter(explode(',', $data['emails']), function ($email) {
                return trim($email) !== '';
            });
        }

        $this->merge($data);
    }

    /**
     * @return void
     */
    protected function failedClientValidation(Validator $validator): void
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
