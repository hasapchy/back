<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreProductRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required',
            'image' => 'nullable|sometimes|file|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'required|string|max:255',
            'description' => 'nullable|sometimes|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'barcode' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'stock_alert_notify' => 'nullable|boolean',
            'stock_min_quantity' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'creator_id' => 'nullable|exists:users,id',
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

        if (isset($data['categories']) && is_string($data['categories'])) {
            $categories = explode(',', $data['categories']);
            $categories = array_map('trim', $categories);
            $categories = array_filter($categories);
            $data['categories'] = $categories;
        }

        if (isset($data['stock_alert_notify'])) {
            $data['stock_alert_notify'] = filter_var($data['stock_alert_notify'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('stock_min_quantity', $data) && $data['stock_min_quantity'] === '') {
            $data['stock_min_quantity'] = null;
        }

        $this->merge($data);
    }

    /**
     * Настроить валидатор
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->all();
            if (empty($data['category_id']) && empty($data['categories'])) {
                $validator->errors()->add('categories', 'Необходимо указать хотя бы одну категорию');
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
