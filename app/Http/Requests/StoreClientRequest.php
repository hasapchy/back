<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesClientFields;
use App\Models\Client;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    use ValidatesClientFields;

    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return $this->clientFieldRules();
    }

    /**
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->prepareClientFieldsForValidation();
    }

    /**
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        $this->failedClientValidation($validator);
    }
}
