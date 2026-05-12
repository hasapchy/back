<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Rules\ClientAccessRule;
use App\Support\ResolvedCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        $lead = Lead::query()->findOrFail((int) $this->route('id'));

        return $this->user()->can('update', $lead);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = ResolvedCompany::fromRequest($this);

        $rules = [
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id', new ClientAccessRule()],
            'lead_source_id' => [
                'nullable',
                'integer',
                Rule::exists('lead_sources', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'status_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('lead_statuses', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'comment' => ['nullable', 'string'],
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
