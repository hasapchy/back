<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
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
        $rules = [
            'name' => 'required|string',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id',
            'description' => 'nullable|string',
        ];

        if ($this->has('budget') || $this->has('currency_id') || $this->has('exchange_rate')) {
            $rules['budget'] = 'required|numeric';
            $rules['currency_id'] = 'nullable|exists:currencies,id';
            $rules['exchange_rate'] = 'nullable|numeric|min:0.000001';
        }

        return $rules;
    }
}

