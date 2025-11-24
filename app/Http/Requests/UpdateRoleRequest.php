<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
        if ($this->has('name')) {
            $this->merge(['name' => trim($this->name)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'permissions' => 'nullable|array|max:1000',
            'permissions.*' => 'string|exists:permissions,name,guard_name,api',
        ];

        if ($this->has('name')) {
            $id = $this->route('id') ?? $this->route('role');
            $companyId = request()->header('X-Company-ID');
            
            $uniqueRule = Rule::unique('roles', 'name')
                ->ignore($id)
                ->where('guard_name', 'api');
            
            if ($companyId) {
                $uniqueRule->where('company_id', $companyId);
            } else {
                $uniqueRule->whereNull('company_id');
            }
            
            $rules['name'] = ['required', 'string', 'max:255', $uniqueRule];
        }

        return $rules;
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('name')) {
                $name = $this->input('name');
                if (isset($name) && empty(trim($name))) {
                    $validator->errors()->add('name', 'Название роли не может быть пустым');
                }
            }
        });
    }
}

