<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateMessageTemplateRequest extends FormRequest
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
        $templateId = $this->route('id');
        $companyId = $this->header('X-Company-ID');
        $isActive = $this->input('is_active');

        $typeRules = ['sometimes', 'string', 'max:255'];
        if (! empty($allowedTypes)) {
            $typeRules[] = Rule::in($allowedTypes);
        }

        // Проверка уникальности: запрещаем создавать активный шаблон с таким же типом для той же компании
        // Проверяем только если:
        // 1. Меняется type И (is_active становится true ИЛИ шаблон уже активен)
        // 2. ИЛИ is_active становится true
        $shouldCheckUniqueness = false;

        if ($this->has('is_active') && $isActive === true) {
            // Активируем шаблон - проверяем уникальность
            $shouldCheckUniqueness = true;
        } elseif ($this->has('type')) {
            // Меняется тип - проверяем уникальность только если шаблон будет активен
            $template = MessageTemplate::find($templateId);
            if ($template && ($isActive === true || ($isActive === null && $template->is_active))) {
                $shouldCheckUniqueness = true;
            }
        }

        if ($shouldCheckUniqueness) {
            $uniqueRule = Rule::unique('message_templates', 'type')
                ->ignore($templateId)
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
            'name' => 'nullable|string|max:255',
            'content' => 'nullable|string',
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
