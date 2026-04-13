<?php

namespace App\Http\Requests;

use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = Template::query()->find($this->route('id'));

        if (! $template) {
            return true;
        }

        return $this->user()->can('update', $template);
    }

    /**
     * @return array<string, mixed>
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
            'note' => 'nullable|string|max:65535',
        ];
    }
}
