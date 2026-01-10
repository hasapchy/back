<?php

namespace App\Http\Requests;

use App\Rules\ProjectAccessRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', new ProjectAccessRule()],
            'type' => 'required|boolean',
            'amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'note' => 'nullable|string',
            'date' => 'required|date',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        if (isset($data['type'])) {
            $data['type'] = filter_var($data['type'], FILTER_VALIDATE_BOOLEAN);
        }
        $this->merge($data);
    }
}
