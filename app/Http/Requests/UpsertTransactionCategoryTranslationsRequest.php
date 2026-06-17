<?php

namespace App\Http\Requests;

use App\Support\TransactionCategoryTranslationDictionary;
use App\Support\TranslationOverrideLocale;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpsertTransactionCategoryTranslationsRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.key' => ['required', 'string'],
            'items.*.locale' => ['required', 'string', 'in:'.implode(',', TranslationOverrideLocale::values())],
            'items.*.value' => ['required', 'string'],
        ];
    }

    /**
     * @param Validator $validator
     * @return void
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $index => $item) {
                $key = (string) ($item['key'] ?? '');
                if ($key === '' || TransactionCategoryTranslationDictionary::has($key)) {
                    continue;
                }
                $validator->errors()->add("items.$index.key", 'Недопустимый ключ категории транзакции.');
            }
        });
    }

    /**
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
