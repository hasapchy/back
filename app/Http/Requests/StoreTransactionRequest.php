<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|integer|in:1,0',
            'orig_amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'cash_id' => 'required|exists:cash_registers,id',
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'is_debt' => 'nullable|boolean',
            'is_adjustment' => 'nullable|boolean'
        ];
    }
}

