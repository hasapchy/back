<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
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
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => 'nullable|string|in:new,in_progress,paid,cancelled',
            'order_ids' => 'nullable|array',
            'order_ids.*' => 'integer|exists:orders,id',
            'products' => 'nullable|array',
            'products.*.product_name' => 'required_with:products|string|max:255',
            'products.*.quantity' => 'required_with:products|numeric|min:0.01',
            'products.*.price' => 'required_with:products|numeric|min:0',
        ];
    }
}

