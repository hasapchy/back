<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Rules\ClientAccessRule;
use App\Support\ResolvedCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = ResolvedCompany::fromRequest($this);

        $rules = [
            'client_id' => ['required', 'integer', 'exists:clients,id', new ClientAccessRule()],
            'title' => ['nullable', 'string', 'max:255'],
            'lead_source_id' => [
                'nullable',
                'integer',
                Rule::exists('lead_sources', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'status_id' => [
                'nullable',
                'integer',
                Rule::exists('lead_statuses', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'comment' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string', 'max:2048'],
        ];
        if ($companyId) {
            $rules['responsible_id'] = [
                'nullable',
                'integer',
                Rule::exists('company_user', 'user_id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ];
        }

        return $rules;
    }
}
