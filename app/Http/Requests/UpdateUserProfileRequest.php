<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UpdateUserProfileRequest extends FormRequest
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
        if ($this->has('birthday') && $this->input('birthday') === '') {
            $this->merge(['birthday' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        return [
            'name' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'birthday' => 'nullable|date',
            'current_password' => 'nullable|string',
            'password' => 'nullable|string|min:6',
            'photo' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
        ];
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
            $user = $this->user();
            
            if ($this->filled('current_password') && !$this->filled('password')) {
                $validator->errors()->add('password', 'Новый пароль обязателен при указании текущего пароля');
            }

            if ($this->filled('password')) {
                if (!$this->filled('current_password')) {
                    $validator->errors()->add('current_password', 'Текущий пароль обязателен для смены пароля');
                } elseif (!Hash::check($this->input('current_password'), $user->password)) {
                    $validator->errors()->add('current_password', 'Неверный текущий пароль');
                }
            }
        });
    }
}

