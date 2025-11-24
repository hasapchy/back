<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCompanyRoundingRuleRequest extends FormRequest
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
            'context' => 'required|string|in:orders,receipts,sales,transactions',
            'decimals' => 'required|integer|min:2|max:5',
            'direction' => 'required|string|in:standard,up,down,custom',
            'custom_threshold' => 'nullable|numeric|min:0|max:1',
        ];
    }
}

