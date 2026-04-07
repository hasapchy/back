<?php

namespace App\Http\Requests;

use App\Models\TransactionCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateTransactionCategoryRequest extends FormRequest
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
            'name' => 'required|string',
            'type' => 'required|boolean',
            'parent_id' => ['nullable', 'integer', 'exists:transaction_categories,id'],
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

        if (isset($data['type'])) {
            $data['type'] = filter_var($data['type'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('parent_id', $data) && ($data['parent_id'] === '' || $data['parent_id'] === false)) {
            $data['parent_id'] = null;
        }

        $this->merge($data);
    }

    /**
     * @param Validator $validator
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $id = (int) $this->route('id');
            $item = TransactionCategory::query()->findOrFail($id);
            $newType = filter_var($this->input('type'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $rawParent = $this->input('parent_id');
            if ($rawParent === null || $rawParent === '') {
                return;
            }
            $parentId = (int) $rawParent;
            if ($parentId === $id) {
                $v->errors()->add('parent_id', __('Категория не может быть родителем самой себя.'));
                return;
            }
            if ($item->children()->exists()) {
                $v->errors()->add('parent_id', __('Нельзя назначить родителя категории, у которой уже есть подкатегории.'));
                return;
            }
            $parent = TransactionCategory::query()->findOrFail($parentId);
            if ((int) $parent->type !== (int) $newType) {
                $v->errors()->add('parent_id', __('Тип родительской категории должен совпадать с типом категории.'));
            } elseif ($parent->parent_id !== null) {
                $v->errors()->add('parent_id', __('Родителем может быть только категория верхнего уровня.'));
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
