<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'icon' => 'sometimes|string|max:255',
            'cash_id' => 'sometimes|integer|exists:cash_registers,id',
            'amount' => 'nullable|numeric|min:0',
            'type' => 'sometimes|in:0,1',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'category_id' => 'nullable|integer|exists:transaction_categories,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string|max:65535',
        ];
    }
}
