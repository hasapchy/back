<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreMessageTemplateRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
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
        $allowedTypes = array_keys(config('template_types', []));
        $companyId = $this->header('X-Company-ID');
        $isActive = $this->input('is_active', true);

        $typeRules = ['required', 'string', 'max:255'];
        if (! empty($allowedTypes)) {
            $typeRules[] = Rule::in($allowedTypes);
        }

        // Проверка уникальности: запрещаем создавать активный шаблон с таким же типом для той же компании
        if ($isActive) {
            $uniqueRule = Rule::unique('message_templates', 'type')
                ->whereNull('deleted_at');

            if ($companyId) {
                $uniqueRule->where('company_id', $companyId);
            } else {
                $uniqueRule->whereNull('company_id');
            }

            // Проверяем только активные шаблоны
            $uniqueRule->where('is_active', true);

            $typeRules[] = $uniqueRule;
        }

        return [
            'type' => $typeRules,
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Получить кастомные сообщения об ошибках валидации
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.unique' => 'Для данной компании уже существует активный шаблон с таким типом. Деактивируйте существующий шаблон или выберите другой тип.',
        ];
    }

    /**
     * Подготовить данные для валидации
     */
    protected function prepareForValidation(): void
    {
        //
    }

    /**
     * Обработать неудачную валидацию
     *
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
