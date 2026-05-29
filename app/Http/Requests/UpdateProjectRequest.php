<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Rules\ClientAccessRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateProjectRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $project = Project::query()->find($this->route('id'));

        if (! $project) {
            return true;
        }

        return $this->user()->can('update', $project);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => ['required', new ClientAccessRule()],
            'users' => 'nullable|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        if ($this->has('currency_id')) {
            $rules['currency_id'] = 'nullable|exists:currencies,id';
        }

        return $rules;
    }

    /**
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->has('currency_id')) {
                return;
            }

            $project = Project::query()->select(['id', 'currency_id'])->find($this->route('id'));
            if (! $project) {
                return;
            }

            $raw = $this->input('currency_id');
            $newCurrencyId = $raw !== null && $raw !== '' ? (int) $raw : null;

            if (! $project->canChangeCurrencyTo($newCurrencyId)) {
                $validator->errors()->add(
                    'currency_id',
                    __('Нельзя изменить валюту проекта: у проекта есть контракты.')
                );
            }
        });
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

