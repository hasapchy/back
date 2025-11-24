<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Category;
use App\Models\CategoryUser;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $controller = new Controller();
        
        // Проверка доступа к кассе
        if ($this->input('cash_id')) {
            $cashAccessCheck = $controller->checkCashRegisterAccess($this->input('cash_id'));
            if ($cashAccessCheck) {
                return false;
            }
        }

        // Проверка доступа к складу
        $warehouseAccessCheck = $controller->checkWarehouseAccess($this->input('warehouse_id'));
        if ($warehouseAccessCheck) {
            return false;
        }

        // Проверка прав на создание временных товаров
        if (!empty($this->input('temp_products'))) {
            if (!$controller->hasPermission('products_create_temp')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'project_id' => 'nullable|sometimes|integer|exists:projects,id',
            'cash_id' => 'nullable|integer|exists:cash_registers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'description' => 'nullable|string',
            'status_id' => 'nullable|integer|exists:order_statuses,id',
            'products' => 'nullable|array',
            'products.*.id' => 'nullable|integer|exists:order_products,id',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity' => 'required_with:products|numeric|min:0',
            'products.*.price' => 'required_with:products|numeric|min:0',
            'products.*.width' => 'nullable|numeric|min:0',
            'products.*.height' => 'nullable|numeric|min:0',
            'temp_products' => 'nullable|array',
            'temp_products.*.id' => 'nullable|integer|exists:order_temp_products,id',
            'temp_products.*.name' => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity' => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.price' => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id' => 'nullable|exists:units,id',
            'temp_products.*.width' => 'nullable|numeric|min:0',
            'temp_products.*.height' => 'nullable|numeric|min:0',
            'remove_temp_products' => 'nullable|array',
            'remove_temp_products.*' => 'integer|exists:order_temp_products,id',
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
            $controller = new Controller();
            $user = auth('api')->user();
            
            if ($user instanceof User && $user->hasRole(config('basement.worker_role'))) {
                $categoryId = $this->input('category_id');
                if ($categoryId) {
                    $userUuid = $controller->getAuthenticatedUserIdOrFail();
                    $companyId = $controller->getCurrentCompanyId();
                    
                    $userCategoryIds = CategoryUser::where('user_id', $userUuid)
                        ->pluck('category_id')
                        ->toArray();

                    if ($companyId) {
                        $companyCategoryIds = Category::where('company_id', $companyId)
                            ->pluck('id')
                            ->toArray();
                        $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
                    }

                    if (!in_array($categoryId, $userCategoryIds)) {
                        $validator->errors()->add('category_id', 'У вас нет доступа к указанной категории');
                    }
                }
            }
        });
    }
}

