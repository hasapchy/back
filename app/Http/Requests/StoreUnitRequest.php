<?php

namespace App\Http\Requests;

use App\Support\ResolvedCompany;
use App\Support\CompanyScopedPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ResolvedCompany::fromRequest($this) !== null
            && CompanyScopedPermissions::userHas($user, 'units_create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cid = ResolvedCompany::fromRequest($this);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'name')->where(fn ($q) => $q->where('company_id', $cid)),
            ],
            'short_name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('units', 'short_name')->where(fn ($q) => $q->where('company_id', $cid)),
            ],
        ];
    }
}
