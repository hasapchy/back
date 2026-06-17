<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientBalanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $client = Client::query()->find($this->route('clientId'));

        if (! $client) {
            return true;
        }

        return $this->user()->can('update', $client);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency_id' => 'required|exists:currencies,id',
            'type' => 'nullable|integer|in:0,1',
            'is_default' => 'boolean',
            'balance' => 'nullable|numeric',
            'note' => 'nullable|string',
            'creator_ids' => 'nullable|array',
            'creator_ids.*' => 'exists:users,id',
        ];
    }
}
