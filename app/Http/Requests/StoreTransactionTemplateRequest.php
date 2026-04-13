<?php

namespace App\Http\Requests;

use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Template::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'icon' => 'required|string|max:255',
            'cash_id' => 'required|integer|exists:cash_registers,id',
            'amount' => 'nullable|numeric|min:0',
            'type' => 'required|in:0,1',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'category_id' => 'nullable|integer|exists:transaction_categories,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'note' => 'nullable|string|max:65535',
        ];
    }
}
