<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
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
            $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
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

        if (isset($data['position']) && trim($data['position']) === '') {
            $data['position'] = null;
        }
        if (isset($data['hire_date']) && trim($data['hire_date']) === '') {
            $data['hire_date'] = null;
        }
        if (isset($data['birthday']) && trim($data['birthday']) === '') {
            $data['birthday'] = null;
        }

        $this->merge($data);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:6',
            'hire_date' => 'nullable|date',
            'birthday' => 'nullable|date',
            'position' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'is_admin' => 'nullable|boolean',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name,guard_name,api',
            'companies' => 'required|array|min:1',
            'companies.*' => 'integer|exists:companies,id',
            'company_roles' => 'nullable|array',
            'company_roles.*.company_id' => 'required_with:company_roles|integer|exists:companies,id',
            'company_roles.*.role_ids' => 'required_with:company_roles|array',
            'company_roles.*.role_ids.*' => 'string|exists:roles,name,guard_name,api',
        ];
    }
}

