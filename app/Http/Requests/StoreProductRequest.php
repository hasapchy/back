<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Unit;
use App\Support\ResolvedCompany;
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
            'product_unit_conversions' => 'nullable|array|max:50',
            'product_unit_conversions.*.parent_unit_id' => 'required|integer|exists:units,id',
            'product_unit_conversions.*.child_unit_id' => 'required|integer|exists:units,id|different:product_unit_conversions.*.parent_unit_id',
            'product_unit_conversions.*.quantity' => 'required|numeric|min:0.00001',
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

        if (isset($data['product_unit_conversions']) && is_string($data['product_unit_conversions'])) {
            $decoded = json_decode($data['product_unit_conversions'], true);
            $data['product_unit_conversions'] = is_array($decoded) ? $decoded : [];
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

        $validator->after(function ($validator) {
            $rows = $this->input('product_unit_conversions');
            if (! is_array($rows) || $rows === []) {
                return;
            }
            $companyId = ResolvedCompany::fromRequest($this);
            $allowedIds = Unit::forCompanyCatalog($companyId)->pluck('id')->map(static fn ($id) => (int) $id)->all();
            foreach ($rows as $idx => $row) {
                $p = (int) ($row['parent_unit_id'] ?? 0);
                $c = (int) ($row['child_unit_id'] ?? 0);
                if (! in_array($p, $allowedIds, true) || ! in_array($c, $allowedIds, true)) {
                    $validator->errors()->add('product_unit_conversions.'.$idx.'.parent_unit_id', __('units.invalid_unit_catalog_scope'));
                }
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
