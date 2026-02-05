<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreUserRequest extends FormRequest
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
            'name'     => 'required|string|max:255',
            'surname'  => 'nullable|string|max:255',
            'email'    => 'required|string|email|unique:central.users,email',
            'password' => 'required|string|min:6',
            'hire_date' => 'nullable|date',
            'birthday' => 'nullable|date',
            'position' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
            'is_admin'   => 'nullable|boolean',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:central.roles,name,guard_name,api',
            'companies' => 'required|array|min:1',
            'companies.*' => 'integer|exists:central.companies,id',
            'company_roles' => 'nullable|array',
            'company_roles.*.company_id' => 'required_with:company_roles|integer|exists:central.companies,id',
            'company_roles.*.role_ids' => 'required_with:company_roles|array',
            'company_roles.*.role_ids.*' => 'string|exists:central.roles,name,guard_name,api',
            'departments' => 'nullable|array',
            'departments.*' => 'integer|exists:departments,id',
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

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['is_admin'])) {
            try {
                $currentUser = auth('api')->user();
                if (!$currentUser || !$currentUser->is_admin) {
                    unset($data['is_admin']);
                } else {
                    $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
                }
            } catch (\Exception $e) {
                unset($data['is_admin']);
            }
        }

        if (isset($data['roles']) && is_string($data['roles'])) {
            $data['roles'] = explode(',', $data['roles']);
        }

        if (isset($data['companies'])) {
            if (is_string($data['companies'])) {
                $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                    return trim($c) !== '';
                });
            }
            if (is_array($data['companies'])) {
                $data['companies'] = array_values(array_map('intval', $data['companies']));
            }
        }

        if (isset($data['company_roles']) && is_string($data['company_roles'])) {
            try {
                $data['company_roles'] = json_decode($data['company_roles'], true);
            } catch (\Exception $e) {
                $data['company_roles'] = [];
            }
        }

        if (isset($data['departments'])) {
            if (is_string($data['departments'])) {
                $data['departments'] = array_filter(explode(',', $data['departments']), function ($d) {
                    return trim($d) !== '';
                });
            }
            if (is_array($data['departments'])) {
                $data['departments'] = array_values(array_map('intval', $data['departments']));
            }
        }

        if (isset($data['position']) && is_string($data['position']) && trim($data['position']) === '') {
            $data['position'] = null;
        }
        if (isset($data['hire_date']) && is_string($data['hire_date']) && trim($data['hire_date']) === '') {
            $data['hire_date'] = null;
        }
        if (isset($data['birthday']) && is_string($data['birthday']) && trim($data['birthday']) === '') {
            $data['birthday'] = null;
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
        throw new \Illuminate\Validation\ValidationException($validator);
    }
}

